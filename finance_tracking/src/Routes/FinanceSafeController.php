<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\Routes;

use DateInterval;
use DateTime;
use DI\Container;
use Exception;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Entity\User\UserVerification;
use Simp\Pindrop\Events\SystemEvents\Events;
use Simp\Pindrop\FactorAuthentication\TwoFactorManager;
use Simp\Pindrop\FileSystem\FileSystemService;
use Simp\Pindrop\Mail\MailManager;
use Simp\Pindrop\Modules\admin\src\Plugin\TwoFactorSettings;
use Simp\Pindrop\Modules\finance_tracking\src\ActiveAccount;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\AccountType;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\TimezoneHelper;
use Simp\Pindrop\Modules\finance_tracking\src\Service\Account;
use Simp\Pindrop\Modules\finance_tracking\src\Service\Associations;
use Simp\Pindrop\Plugin\PluginManager;
use Simp\Pindrop\Routing\AttributeRoute;
use Simp\Pindrop\Routing\Url;
use Simp\Pindrop\Session\SessionStorage;
use Simp\Pindrop\Settings\Settings;
use Simp\Price\lib\Currency;
use Simp\Price\lib\Price;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class FinanceSafeController extends ControllerBase
{

    public function __construct(protected readonly ?CurrentUser $current_user)
    {
        parent::__construct();
    }

    public static function create(Container $container): FinanceSafeController
    {
        return new static(
            $container->get('current_user')
        );
    }
    public static function nav(string $name): array
    {
        return [
            'Overview' => [
                'title' => 'Overview'
            ],
            'CreateAccount' => [
                'title' => 'Create finance account'
            ],
            'SelectorAccount' => [
                'title' => 'Account selector'
            ],
            'Associations' => [
                'title' => 'Associations'
            ]
        ][$name];
    }

    #[AttributeRoute('/finances/create-finance-account', ['GET', 'POST'], permission: ['can_manage_own_finance_accounts'])]
    public function _financeAccountCreate(Request $request, string $route_name, array $options)
    {

        $_SESSION['finance_tracking.force.redirect'] = false;

        $accountTypes = AccountType::all();
        $timezones = TimezoneHelper::all();
        $currencies = new Currency();
        $list = $currencies->getCurrencyGroup();

        if ($request->isMethod(Request::METHOD_POST)) {
            $submitted = $request->request->all();
            $account = new ActiveAccount(getAppContainer()->get('database'), null)
                ->setAccountBalance(new Price(0, $submitted['currency']))
                ->setAccountCurrency($submitted['currency'])
                ->setDisplayName($submitted['name'])
                ->setAccountEmail($submitted['email'])
                ->setAccountPhone($submitted['phone'])
                ->setAccountTimezone($submitted['timezone'])
                ->setInstitutionName($submitted['inst_name'])
                ->setAccountType($submitted['purpose'])
                ->setAccountOwner($this->current_user->getUser()->getId())
                ->save();

            if ($account instanceof ActiveAccount) {
                $_SESSION['finance_tracking.force.redirect.lock'] = false;
                return $this->redirect('/finances/select-account');
            }

        }

        return $this->renderTwig("@finance_tracking/create-finance-account.html.twig", [
            'nav' => self::nav('CreateAccount'),
            'account_types' => $accountTypes,
            'timezones' => $timezones,
            'currencies' => $list
        ]);
    }

    #[AttributeRoute('/login', ['GET', 'POST'], permission: [])]
    public function _login(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            try {
                // Validate required fields
                if (empty($data['email']) || empty($data['password'])) {
                    throw new InvalidArgumentException("Email and password are required");
                }

                // Find user by email
                $user = User::loadByEmail($data['email'], getAppContainer()->get('database'));
                if (!$user) {
                    throw new InvalidArgumentException("Invalid credentials");
                }

                // Verify password
                if (!$user->verifyPassword($data['password'])) {
                    throw new InvalidArgumentException("Invalid credentials");
                }

                // Check user status
                if ($user->getStatus() === User::STATUS_BANNED) {
                    throw new InvalidArgumentException("Account banned");
                }

                if ($user->getStatus() === User::STATUS_SUSPENDED) {
                    throw new InvalidArgumentException("Account suspended");
                }

                $settings = new Settings(getAppContainer()->get('database'));
                $twoFactor = $settings->getSetting(new TwoFactorSettings()->settingKey());

                if ($twoFactor && $twoFactor->get('is_enabled') == 1) {
                    appEvents()->invokeEvents(Events::TWO_FACTOR_AUTHENTICATION_REQUIRED, [
                        "user" => $user,
                    ]);

                    $provider = $twoFactor->get('two_factor_key');
                    if ($provider) {
                        $providerManager = new TwoFactorManager(getAppContainer()->get('plugin.manager'));
                        $provider = $providerManager->getTwofactorAuthenticationProvider($provider);

                        if (!empty($provider)) {
                            SessionStorage::add('two_factor_session', [
                                'provider' => $provider?->key(),
                                'user' => $user->getId(),
                            ]);

                            return $this->redirect($provider?->redirectLink());
                        }
                    }
                }

                // Create session
                $sessionId = session_id();
                $session = new CurrentUser(getAppContainer()->get('database'), getAppContainer()->get('logger'));
                $session->setUserId($user->getId());
                $session->setSessionId($sessionId);
                $session->setIpAddress($request->getClientIp());
                $session->setUserAgent($request->headers->get('User-Agent'));
                $session->setExpiresAt((new DateTime())->add(new DateInterval('PT24H')));
                $session->setUser($user);
                $session->setUserData($user->toArray());

                if ($session->create()) {
                    // Set session cookie

                    $response = new RedirectResponse(!empty($url) ? $url : '/finances/select-account');
                    $response->headers->setCookie(
                        new Cookie(
                            'session_id',
                            $sessionId,
                            new DateTime('+24 hours'),
                            '/',
                            null,
                            true,
                            true,
                        )
                    );
                    getAppContainer()->get('logger')->info('User logged in successfully', [
                        'user_id' => $user->getId(),
                        'email' => $user->getEmail(),
                        'ip' => $request->getClientIp()
                    ]);

                    appEvents()->invokeEvents(Events::AUTH_LOGIN, ['session_id' => $sessionId]);

                    return $response;
                } else {
                    appEvents()->invokeEvents(Events::AUTH_LOGIN_FAILED, [
                        'email' => $data['email']
                    ]);
                    throw new RuntimeException("Failed to create session");
                }

            } catch (Exception $e) {

                appEvents()->invokeEvents(Events::AUTH_LOGIN_FAILED, [
                    'email' => $data['email']
                ]);
                getAppContainer()->get('logger')->error('Login failed', [
                    'error' => $e->getMessage(),
                    'email' => $data['email'] ?? 'unknown',
                    'ip' => $request->getClientIp()
                ]);

                return $this->renderTwig('@finance_tracking/login.html.twig', [
                    'page_title' => 'Login',
                    'error' => $e->getMessage(),
                    'old_input' => $data
                ]);
            }
        }
        return $this->renderTwig("@finance_tracking/login.html.twig");
    }

    #[AttributeRoute('/register', ['GET', 'POST'], permission: [])]
    public function _register(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod(Request::METHOD_POST)) {

            try {
                $submitted = $request->request->all();
                $name_list = explode(" ", $submitted['name'] ?? "");
                $user = new User([
                    'first_name' => $name_list[0] ?? "",
                    'last_name' => $name_list[1] ?? "",
                    'email' => $submitted['email'],
                    'username' => explode("@", $submitted['email'] ?? "")[0] ?? time(),
                    'role' => $submitted['role'] ?? "finance.accounts.owner",
                ], getAppContainer()->get('database'));

                $user->setPassword($submitted['password']);

                if ($user->save()) {
                    // Create email verification token
                    $verification = UserVerification::createEmailVerificationToken(
                        getAppContainer()->get('database'),
                        getAppContainer()->get('logger'),
                        $user->getId(),
                        $user->getEmail(),
                        $request->getClientIp(),
                        $request->headers->get('User-Agent')
                    );

                    if ($verification) {

                        $content = $this->renderTwig("@admin/emails/verify_email.twig", [
                            'user' => $user,
                            'verification_link' => $request->getSchemeAndHttpHost() . Url::routeByName('user.verify_email', [
                                'token' => $verification->getToken()
                            ])
                        ]);

                        /**
                         * @var MailManager
                         */
                        $mailManager = getAppContainer()->get('mail.manager');
                        $mailManager->sendHtml(
                            $verification->getEmail(),
                            $_ENV['MAIL_VERIFICATION_SUBJECT'] ?? "Verify your email",
                            $content->getContent()
                        );

                        getAppContainer()->get('logger')->info('Registration successful, verification email sent', [
                            'user_id' => $user->getId(),
                            'email' => $user->getEmail()
                        ]);
                    }

                    return new RedirectResponse('/user/login?message=registration_success');
                }

            } catch (Throwable $e) {
            }
        }

        $pluginManager = getAppContainer()->get(PluginManager::class);

        $roles = $pluginManager->getPluginYamlContent('finance_tracking', 'user.roles');

        return $this->renderTwig("@finance_tracking/register.html.twig", [
            'roles' => $roles,
        ]);
    }

    #[AttributeRoute('/finances/profile', ['GET', 'POST'], permission: ['can_manage_own_profile'])]
    public function _profile(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod(Request::METHOD_POST)) {
            $submitted = $request->request->all();

            $user = $this->current_user?->getUser();
            /**
             * @var UploadedFile
             */
            $file = $request->files->get('profile');
            $fileObject = null;
            if (!empty($file)) {

                /**
                 * @var FileSystemService
                 */
                $fileSystem = getAppContainer()->get('filesystem');
                $destination_uri = "public://finances/profiles/" . $file->getClientOriginalName();

                $fileObject = $fileSystem->getFileSystem()->uploadAndSaveFileEntity($file, $destination_uri, [
                    'allowed_types' => ['image/png', 'image/jpg', 'image/jpeg', 'png', 'jpeg', 'jpg'],
                    'max_size' => 5000000,
                    'unique' => true,
                    'alt' => "Profile image for " . $user->getFirstName() . " " . $user->getLastName(),
                ]);

                $user->setAvatarUrl($fileObject->getUri());

            }


            if ($user instanceof User) {

                $user->setFirstName($submitted['first_name'] ?? $user->getFirstName());
                $user->setLastName($submitted['last_name'] ?? $user->getLastName());
                $user->setProfileVisibility($submitted['visibility'] === 'on' ? 'public' : 'private');

                $user->save();
                return $this->redirect('/finances/select-account');
            }
        }

        return $this->renderTwig("@finance_tracking/profile.html.twig", [
            'profile' => User::loadById($this->current_user?->getUser()->getId(), getAppContainer()->get('database'))
        ]);
    }

    #[AttributeRoute('/finances/associations', ['GET', 'POST'], permission: ['can_manage_own_association'])]
    public function _associateProfile(Request $request, string $route_name, array $options)
    {
        $database = getAppContainer()->get('database');
        $associations = new Associations($database);
        $accountService = new Account($database, $this->current_user);
        $activeAccount = $accountService->loadActiveAccount();
        $me = $this->current_user?->getUser();

        // Associations only make sense for a selected account that the current user owns.
        if (!$activeAccount instanceof ActiveAccount) {
            return $this->redirect('/finances/select-account');
        }

        if ((int) $activeAccount->getAccountOwner()->getId() !== (int) $me?->getId()) {
            return $this->redirect('/finances/select-account');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $submitted = $request->request->all();
            $action = $submitted['action'] ?? '';
            $managerId = (int) ($submitted['manager_id'] ?? 0);
            $message = 'error';

            $manager = $managerId > 0 ? User::loadById($managerId, $database) : null;

            if ($manager instanceof User) {
                if ($action === 'grant' && $associations->isAvailableManager($managerId)) {
                    $message = $associations->grantAccountAccess($manager, $activeAccount) ? 'granted' : 'error';
                } elseif ($action === 'revoke') {
                    $message = $associations->revokeAccountAccess($manager, $activeAccount) ? 'revoked' : 'error';
                }
            }

            return $this->redirect('/finances/associations?message=' . $message);
        }

        $connected = $associations->getAssociatedManagers($activeAccount);
        $connectedIds = array_map(fn(User $u) => (int) $u->getId(), $connected);

        $available = array_values(array_filter(
            $associations->getManagers(),
            fn($m) => $m instanceof User && !in_array((int) $m->getId(), $connectedIds, true)
        ));

        return $this->renderTwig("@finance_tracking/associations.html.twig", [
            'nav' => self::nav('Associations'),
            'account' => $activeAccount,
            'connected_managers' => $connected,
            'available_managers' => $available,
            'message' => $request->query->get('message'),
        ]);
    }
}
