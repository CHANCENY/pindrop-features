<?php

namespace Simp\Pindrop\Modules\mobile_app\src\Controller;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Entity\User\UserVerification;
use Simp\Pindrop\Events\SystemEvents\Events;
use Simp\Pindrop\FactorAuthentication\TwoFactorManager;
use Simp\Pindrop\Mail\MailManager;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\admin\src\Plugin\TwoFactorSettings;
use Simp\Pindrop\Modules\mobile_app\src\Plugin\Service\MobileSettingsService;
use Simp\Pindrop\Routing\Url;
use Simp\Pindrop\Session\SessionStorage;
use Simp\Pindrop\Settings\Settings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class ManifestController extends ControllerBase
{
    public function __construct(
        private MobileSettingsService $settings,
        protected DatabaseService $database_service
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get(MobileSettingsService::class),
            $container->get('database')
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function currentUser(): ?CurrentUser
    {
        return getAppContainer()->get('current_user');
    }

    private function isPost(Request $request): bool
    {
        return $request->isMethod('POST');
    }

    // ── PWA manifest ──────────────────────────────────────────────────────

    public function manifest(Request $request, string $route_name, array $options)
    {
        $json = $this->renderTwig('mobile/pwa/manifest.json.twig', [
            'mobile_settings' => $this->settings->toJson(),
            'app_name' => $this->settings->get('pwa.app_name', $_ENV['APP_NAME'] ?? 'Pindrop App'),
        ]);

        return $this->render($json->getContent(), 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    // ── Home ──────────────────────────────────────────────────────────────

    public function home(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig('mobile/home.html.twig');
    }

    // ── Profile ───────────────────────────────────────────────────────────

    public function profile(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig('mobile/profile.html.twig');
    }

    // ── Profile Edit ──────────────────────────────────────────────────────

    public function profileEdit(Request $request, string $route_name, array $options)
    {
        $currentUser = $this->currentUser();

        if ($this->isPost($request)) {
            try {
                $data = $request->request->all();
                $user = $currentUser?->getUser();

                if (!$user) {
                    throw new RuntimeException("User not found");
                }

                // Update fields that exist on the user array
                if (!empty($data['display_name'])) {
                    $user->setDisplayName($data['display_name']);
                }
                if (!empty($data['username'])) {
                    $user->setUsername($data['username']);
                }
                if (isset($data['bio'])) {
                    $user->setBio($data['bio']);
                }
                if (!empty($data['email'])) {
                    $user->setEmail($data['email']);
                }

                // Handle avatar upload
                $avatarFile = $request->files->get('avatar');
                if ($avatarFile && $avatarFile->isValid()) {
                    $uploadDir = $_ENV['UPLOAD_PATH'] ?? 'public/uploads/avatars';
                    $filename = 'avatar_' . $user->getId() . '_' . time() . '.' . $avatarFile->getClientOriginalExtension();
                    $avatarFile->move($uploadDir, $filename);
                    $user->setAvatarUrl('/uploads/avatars/' . $filename);
                }

                if ($user->save()) {
                    Message::info("Profile updated successfully");
                    return $this->redirect(Url::routeByName('mobile_app.profile'));
                }

                throw new RuntimeException("Failed to save profile");

            } catch (Throwable $e) {
                Message::error($e->getMessage());
                getAppContainer()->get('logger')->error('Profile edit failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->renderTwig('mobile/profile_edit.html.twig');
    }

    // ── Profile Share ─────────────────────────────────────────────────────

    public function profileShare(Request $request, string $route_name, array $options)
    {
        $currentUser = $this->currentUser();
        $user = $currentUser?->getUser();
        $baseUrl = $_ENV['APP_URL'] ?? $request->getSchemeAndHttpHost();
        $profileUrl = $user ? $baseUrl . '/mobile/profile' : $baseUrl;

        return $this->renderTwig('mobile/profile_share.html.twig', [
            'profile_url' => $profileUrl,
            'qr_url' => null, // wire up a QR library if needed
        ]);
    }

    // ── Search ────────────────────────────────────────────────────────────

    public function search(Request $request, string $route_name, array $options)
    {
        $query = trim($request->query->get('q', ''));
        $results = [];

        if ($query !== '') {
            try {
                // Basic full-text search across users table as a starting point.
                // Extend with content-type searches as your app grows.
                $stmt = $this->database_service->getPdo()->prepare(
                    "SELECT id, username, display_name, bio, avatar_url
                     FROM users
                     WHERE (username LIKE :q OR display_name LIKE :q OR bio LIKE :q)
                       AND deleted_at IS NULL
                     LIMIT 30"
                );
                $stmt->execute(['q' => '%' . $query . '%']);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $results[] = [
                        'url' => Url::routeByName('mobile_app.profile') . '?u=' . $row['id'],
                        'title' => $row['display_name'] ?: $row['username'],
                        'excerpt' => $row['bio'] ? mb_substr($row['bio'], 0, 80) . '…' : '@' . $row['username'],
                        'icon' => null,
                    ];
                }
            } catch (Throwable $e) {
                getAppContainer()->get('logger')->error('Search failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->renderTwig('mobile/search.html.twig', [
            'query' => $query,
            'results' => $results,
        ]);
    }

    // ── Settings ──────────────────────────────────────────────────────────

    public function settings(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig('mobile/settings.html.twig', [
            'current_theme_setting' => $_COOKIE['pd_theme'] ?? 'auto',
        ]);
    }

    // ── Settings: Save (JSON endpoint) ────────────────────────────────────

    public function save(Request $request, string $route_name, array $options)
    {
        $currentUser = $this->currentUser();
        $user = $currentUser?->getUser();

        try {
            $body = json_decode($request->getContent(), true) ?? [];
            $key = $body['key'] ?? null;
            $value = $body['value'] ?? null;

            if (!$key) {
                return new JsonResponse(['success' => false, 'error' => 'Missing key'], 400);
            }

            // Persist to user record for known user-level settings
            $userFields = [
                'push_notifications',
                'email_notifications',
                'sms_notifications',
                'profile_visibility',
                'language',
                'timezone',
            ];

            if ($user && in_array($key, $userFields, true)) {
                $setter = 'set' . str_replace('_', '', ucwords($key, '_'));
                if (method_exists($user, $setter)) {
                    $user->$setter($value);
                    $user->save();
                }
            }

            // Theme is stored in a cookie (client-side preference)
            $response = new JsonResponse(['success' => true]);
            if ($key === 'theme') {
                $response->headers->setCookie(
                    new Cookie('pd_theme', $value, new DateTime('+1 year'), '/', null, false, false)
                );
            }

            return $response;

        } catch (Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Login ─────────────────────────────────────────────────────────────

    public function login(Request $request, string $route_name, array $options)
    {
        try {
            if ($this->isPost($request)) {
                $data = $request->request->all();

                if (empty($data['email']) || empty($data['password'])) {
                    throw new InvalidArgumentException("Email and password are required");
                }

                $user = User::loadByEmail($data['email'], $this->database_service);
                if (!$user || !$user->verifyPassword($data['password'])) {
                    throw new InvalidArgumentException("Invalid credentials");
                }

                if ($user->getStatus() === User::STATUS_BANNED)
                    throw new InvalidArgumentException("Account banned");
                if ($user->getStatus() === User::STATUS_SUSPENDED)
                    throw new InvalidArgumentException("Account suspended");

                
                 $settings = new Settings($this->database_service);
                $twoFactor = $settings->getSetting(new TwoFactorSettings()->settingKey());

                if ($twoFactor && $twoFactor->get('is_enabled') == 1) {
                    appEvents()->invokeEvents(Events::TWO_FACTOR_AUTHENTICATION_REQUIRED, [
                        "user" => $user,
                    ]);

                    $provider = $twoFactor->get('two_factor_key');
                    if ($provider) {
                        $providerManager = new TwoFactorManager(getAppContainer()->get('plugin.manager'));
                        $provider = $providerManager->getTwofactorAuthenticationProvider($provider);

                        SessionStorage::add('two_factor_session', [
                            'provider' => $provider->key(),
                            'user' => $user->getId(),
                        ]);

                        return $this->redirect($provider?->redirectLink());
                    }
                }

                $sessionId = session_id();
                $session = new CurrentUser($this->database_service, getAppContainer()->get('logger'));
                $session->setUserId($user->getId());
                $session->setSessionId($sessionId);
                $session->setIpAddress($request->getClientIp());
                $session->setUserAgent($request->headers->get('User-Agent'));
                $session->setExpiresAt((new DateTime())->add(new DateInterval('PT24H')));

                if ($session->create()) {
                    $response = new RedirectResponse(Url::routeByName('mobile_app.home'));
                    $response->headers->setCookie(new Cookie(
                        'session_id',
                        $sessionId,
                        new DateTime('+24 hours'),
                        '/',
                        null,
                        true,
                        true
                    ));
                    return $response;
                }

                throw new RuntimeException("Failed to create session");
            }
        } catch (Throwable $e) {
            Message::error($e->getMessage());
        }

        return $this->renderTwig('mobile/login.html.twig');
    }

    // ── Register ──────────────────────────────────────────────────────────

    public function register(Request $request, string $route_name, array $options)
    {
        if ($this->isPost($request)) {
            try {
                $data = $request->request->all();

                if (empty($data['email']) || empty($data['password']) || empty($data['username'])) {
                    throw new InvalidArgumentException("Username, email and password are required");
                }

                if ($data['password'] !== ($data['password_confirmation'] ?? '')) {
                    throw new InvalidArgumentException("Passwords do not match");
                }

                // Check uniqueness
                $existing = User::loadByEmail($data['email'], $this->database_service);
                if ($existing)
                    throw new InvalidArgumentException("Email is already registered");

                $user = new User([], $this->database_service);
                $user->setUsername($data['username']);
                $user->setEmail($data['email']);
                $user->setPassword($data['password']);

                if (!empty($data['display_name'])) {
                    $user->setDisplayName($data['display_name']);
                }

                if ($user->save()) {
                    Message::info("Account created! Please log in.");
                    return $this->redirect(Url::routeByName('mobile_app.user.login'));
                }

                throw new RuntimeException("Failed to create account");

            } catch (Throwable $e) {
                Message::error($e->getMessage());
            }
        }

        return $this->renderTwig('mobile/register.html.twig');
    }

    // ── Forgot Password ───────────────────────────────────────────────────

    public function forgotPassword(Request $request, string $route_name, array $options)
    {
        if ($this->isPost($request)) {
            $email = $request->request->get('email', '');
            try {

                $user = User::loadByEmail($email, $this->database_service);
                if (!$user)
                    throw new InvalidArgumentException('We have sent the reset link to your email if it exists in our system.');
                // Create password reset token
                $verification = UserVerification::createPasswordResetToken(
                    $this->database_service,
                    getAppContainer()->get('logger'),
                    $user->getId(),
                    $user->getEmail(),
                    $request->getClientIp(),
                    $request->headers->get('User-Agent')
                );

                if ($verification) {

                    $mailContent = $this->renderTwig('@admin/emails/password_reset.twig', [
                        'user' => $user->toArray(),
                        'reset_link' => Url::routeByName('user.reset_password', ['token' => $verification->getToken()], true)
                    ]);

                    /**
                     * @var MailManager $mailManager 
                     * 
                     */
                    $mailManager = getAppContainer()->get('mail.manager');

                    $mailManager->sendHtml(
                        $user->getEmail(),
                        'Password Reset Request',
                        $mailContent->getContent()
                    );

                    getAppContainer()->get('logger')->info('Password reset token created', [
                        'user_id' => $user->getId(),
                        'email' => $user->getEmail()
                    ]);
                }


            } catch (Throwable $e) {
                getAppContainer()->get('logger')->error('Forgot password error', ['error' => $e->getMessage()]);
            }
            Message::info("If that email exists, a reset link has been sent.");
            return $this->redirect(Url::routeByName('mobile_app.user.login'));
        }

        return $this->renderTwig('mobile/forgot_password.html.twig');
    }

    // ── Logout ────────────────────────────────────────────────────────────

    public function logout(Request $request, string $route_name, array $options)
    {
        $sessionId = session_id();
        if ($sessionId) {
            $session = CurrentUser::findBySessionId(
                $this->database_service,
                getAppContainer()->get('logger'),
                $sessionId
            );
            if ($session)
                $session->delete();
        }

        $response = new RedirectResponse(Url::routeByName('mobile_app.user.login'));
        $response->headers->clearCookie('session_id');
        return $response;
    }

    // ── Delete Account ────────────────────────────────────────────────────

    public function deleteAccount(Request $request, string $route_name, array $options)
    {
        $currentUser = $this->currentUser();
        if ($currentUser?->isLoggedIn()) {
            $user = $currentUser->getUser();
            if ($user) {
                $user->delete();
                Message::info("Account deleted successfully");
                return $this->logout($request, '', []);
            }
        }

        Message::error("Unable to delete account");
        return $this->redirect(Url::routeByName('mobile_app.profile'));
    }

    // ── Password Change ───────────────────────────────────────────────────

    public function password(Request $request, string $route_name, array $options)
    {
        $currentUser = $this->currentUser();

        if ($this->isPost($request)) {
            $data = $request->request->all();

            if (empty($data['current_password']) || empty($data['new_password']) || empty($data['confirm_password'])) {
                Message::error("All fields are required");
                return $this->redirect(Url::routeByName('mobile_app.settings.password'));
            }

            if ($data['new_password'] !== $data['confirm_password']) {
                Message::error("New password and confirmation do not match");
                return $this->redirect(Url::routeByName('mobile_app.settings.password'));
            }

            if (!$currentUser->verifyPassword($data['current_password'])) {
                Message::error("Current password is incorrect");
                return $this->redirect(Url::routeByName('mobile_app.settings.password'));
            }

            $user = $currentUser->getUser();
            if ($user) {
                $user->setPassword($data['new_password']);
                if ($user->save()) {
                    Message::info("Password updated successfully");
                    return $this->redirect(Url::routeByName('mobile_app.profile'));
                }
                Message::error("Failed to update password");
            }
        }

        return $this->renderTwig('mobile/password.html.twig');
    }

    // ── Sessions ──────────────────────────────────────────────────────────

    public function sessions(Request $request, string $route_name, array $options)
    {
        $currentUser = $this->currentUser();

        if (!$currentUser?->isLoggedIn()) {
            return $this->redirect(Url::routeByName('mobile_app.user.login'));
        }

        if ($this->isPost($request)) {
            $data = $request->request->all();

            if (!empty($data['revoke_session'])) {
                // Revoke single session by id
                try {
                    $stmt = $this->database_service->getPdo()->prepare(
                        "DELETE FROM user_sessions WHERE id = :id AND user_id = :uid"
                    );
                    $stmt->execute([
                        'id' => $data['revoke_session'],
                        'uid' => $currentUser->getUserId(),
                    ]);
                    Message::info("Session revoked");
                } catch (Throwable $e) {
                    Message::error("Could not revoke session");
                }
            }

            if (!empty($data['revoke_all'])) {
                // Revoke all sessions except the current one
                try {
                    $stmt = $this->database_service->getPdo()->prepare(
                        "DELETE FROM user_sessions WHERE user_id = :uid AND session_id != :sid"
                    );
                    $stmt->execute([
                        'uid' => $currentUser->getUserId(),
                        'sid' => session_id(),
                    ]);
                    Message::info("All other sessions signed out");
                } catch (Throwable $e) {
                    Message::error("Could not revoke sessions");
                }
            }

            return $this->redirect(Url::routeByName('mobile_app.settings.sessions'));
        }

        $sessions = $currentUser->getSessions();

        return $this->renderTwig('mobile/sessions.html.twig', [
            'sessions' => $sessions,
            'app_session_id' => session_id(),
        ]);
    }

    // ── Two-Factor Auth ───────────────────────────────────────────────────

    public function twoFactor(Request $request, string $route_name, array $options)
    {
        $currentUser = $this->currentUser();
        $user = $currentUser?->getUser();

        if ($this->isPost($request) && $user) {
            $action = $request->request->get('action');

            if ($action === 'setup') {
                // Generate a TOTP secret and QR code URL
                // Requires a TOTP library — placeholder implementation
                $secret = strtoupper(substr(base64_encode(random_bytes(20)), 0, 32));
                $otpUrl = 'otpauth://totp/' . rawurlencode($user->getEmail())
                    . '?secret=' . $secret
                    . '&issuer=' . rawurlencode($_ENV['APP_NAME'] ?? 'Pindrop');

                // Store secret temporarily in session
                $_SESSION['2fa_setup_secret'] = $secret;

                return $this->renderTwig('mobile/two_factor.html.twig', [
                    'qr_code' => 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($otpUrl),
                    'secret' => $secret,
                ]);
            }

            if ($action === 'verify') {
                $secret = $_SESSION['2fa_setup_secret'] ?? $request->request->get('secret');
                $code = preg_replace('/\D/', '', $request->request->get('code', ''));

                // TODO: validate TOTP code against secret using a library
                // Assuming valid for now — replace with real TOTP validation
                $user->setTwoFactorEnabled(1);
                $user->setTwoFactorSecret($secret);
                if ($user->save()) {
                    unset($_SESSION['2fa_setup_secret']);
                    Message::info("Two-factor authentication enabled");
                    return $this->redirect(Url::routeByName('mobile_app.settings'));
                }
                Message::error("Failed to enable 2FA");
            }

            if ($action === 'disable') {
                $user->setTwoFactorEnabled(0);
                $user->setTwoFactorSecret(null);
                if ($user->save()) {
                    Message::info("Two-factor authentication disabled");
                    return $this->redirect(Url::routeByName('mobile_app.settings'));
                }
                Message::error("Failed to disable 2FA");
            }
        }

        return $this->renderTwig('mobile/two_factor.html.twig');
    }

    // ── Verify Email ──────────────────────────────────────────────────────

    public function verifyEmail(Request $request, string $route_name, array $options)
    {
        $currentUser = $this->currentUser();
        $user = $currentUser?->getUser();

        // Handle click from email link: /mobile/settings/verify-email?token=xxx
        $token = $request->query->get('token');
        if ($token && $user) {
            $userVerification = UserVerification::findByToken(
                $this->database_service,
                getAppContainer()->get('logger'),
                $token
            );


            if ($userVerification && $userVerification->isValid() && $userVerification->getUserId() === $user->getId()) {
                $user->setVerifiedAt(new DateTime());
                $user->setIsVerified(true);
                $user->setVerificationMethod('email');
                $user->save();
                Message::info("Email verified successfully");
                return $this->renderTwig('mobile/verify_email.html.twig', ['token' => $token]);
            }
            Message::error("Invalid or expired verification link");
        }

        // Resend action from form POST
        if ($this->isPost($request)) {
            $action = $request->request->get('action');
            if ($action === 'resend' && $user) {

                $token = UserVerification::createEmailVerificationToken(
                    $this->database_service,
                    getAppContainer()->get('logger'),
                    $user->getId(),
                    $user->getEmail(),
                    $request->getClientIp(),
                    $request->headers->get('User-Agent')
                );

                $mailContent = $this->renderTwig('@admin/emails/verify_email.twig', [
                    'user' => $user ? $user->toArray() : null,
                    'verification_link' => Url::routeByName('user.verify_email', ['token' => $token->getToken()], true)
                ]);


                /**
                 * @var MailManager $mailManager 
                 * 
                 */
                $mailManager = getAppContainer()->get('mail.manager');
                $mailManager->sendHtml(
                    $user->getEmail(),
                    'Verify Your Email Address',
                    $mailContent->getContent()
                );
            
                Message::info("Verification email sent. Check your inbox.");
            }
        } else {

            $token = UserVerification::createEmailVerificationToken(
                $this->database_service,
                getAppContainer()->get('logger'),
                $user->getId(),
                $user->getEmail(),
                $request->getClientIp(),
                $request->headers->get('User-Agent')
            );

            $mailContent = $this->renderTwig('@admin/emails/verify_email.twig', [
                'user' => $user ? $user->toArray() : null,
                'verification_link' => Url::routeByName('user.verify_email', ['token' => $token->getToken()], true)
            ]);


            /**
             * @var MailManager $mailManager 
             * 
             */
            $mailManager = getAppContainer()->get('mail.manager');
            $mailManager->sendHtml(
                $user->getEmail(),
                'Verify Your Email Address',
                $mailContent->getContent()
            );
            Message::info('Verification email sent');
        }

        return $this->renderTwig('mobile/verify_email.html.twig');
    }

    // ── Timezone ──────────────────────────────────────────────────────────

    public function timezone(Request $request, string $route_name, array $options)
    {
        $currentUser = $this->currentUser();

        if ($this->isPost($request)) {
            $tz = $request->request->get('timezone', 'UTC');
            $user = $currentUser?->getUser();

            if ($user && in_array($tz, \DateTimeZone::listIdentifiers())) {
                $user->setTimezone($tz);
                if ($user->save()) {
                    Message::info("Timezone updated to $tz");
                    return $this->redirect(Url::routeByName('mobile_app.settings'));
                }
                Message::error("Failed to update timezone");
            }
        }
        $keys = \DateTimeZone::listIdentifiers();
        $values = array_combine($keys, $keys);
        return $this->renderTwig('mobile/timezone.html.twig', ['timezones' => $values]);
    }

    // ── Storage ───────────────────────────────────────────────────────────

    public function storage(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig('mobile/storage.html.twig', [
            'cache_size' => '0 KB',
        ]);
    }

    // ── About ─────────────────────────────────────────────────────────────

    public function about(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig('mobile/about.html.twig');
    }
}
