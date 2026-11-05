<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\northstar\src\Controller;

use InvalidArgumentException;
use DateInterval;
use DateTime;
use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Entity\User\UserVerification;
use Simp\Pindrop\FactorAuthentication\TwoFactorManager;
use Simp\Pindrop\Mail\MailManager;
use Simp\Pindrop\Modules\admin\src\Plugin\TwoFactorSettings;
use Simp\Pindrop\Modules\stockflow\src\Payment\PaymentMethodRegistry;
use Simp\Pindrop\Modules\stockflow\src\Services\AdjustmentConfigurationService;
use Simp\Pindrop\Modules\stockflow\src\Services\ManualOrderService;
use Simp\Pindrop\Modules\stockflow\src\Services\ShippingMethodService;
use Simp\Pindrop\Modules\stockflow\src\Services\StockflowService;
use Simp\Pindrop\Routing\Url;
use Simp\Pindrop\Session\SessionStorage;
use Simp\Pindrop\Settings\Settings;
use Simp\Price\lib\Price;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class NorthstarController extends ControllerBase
{
    private const CART_KEY = 'northstar.cart';
    private const CHECKOUT_DATA_KEY = 'northstar.checkout_data';
    private const CHECKOUT_REVIEW_KEY = 'northstar.checkout_review';
    private const CONFIRMATION_KEY = 'northstar.confirmation';

    public function __construct(
        private readonly StockflowService $stockflow,
        private readonly ManualOrderService $orders,
        private readonly ShippingMethodService $shipping,
        private readonly PaymentMethodRegistry $paymentMethods,
        private readonly AdjustmentConfigurationService $adjustments,
        private readonly DatabaseService $database
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new self(
            $container->get('stockflow.service'),
            $container->get('stockflow.manual_orders'),
            $container->get('stockflow.shipping_methods'),
            $container->get('stockflow.payment_methods'),
            $container->get('stockflow.adjustments'),
            $container->get('database')
        );
    }

    public function home(Request $request, string $route_name, array $options): Response
    {
        $products = array_values(array_filter(
            $this->getCatalogProducts(),
            static fn (array $product): bool => $product['available']
        ));
        return $this->renderPage('@northstar/home.twig', [
            'products' => array_slice($products, 0, 4),
        ]);
    }

    public function catalog(Request $request, string $route_name, array $options): Response
    {
        $products = array_values(array_filter(
            $this->getCatalogProducts(),
            static fn (array $product): bool => $product['available']
        ));
        $query = trim($this->queryString($request, 'q'));
        $categoryId = $this->queryString($request, 'category');
        $sort = $this->queryString($request, 'sort');

        if ($query !== '') {
            $products = array_values(array_filter(
                $products,
                static fn (array $product): bool =>
                    stripos($product['name'], $query) !== false
                    || stripos((string) $product['brand'], $query) !== false
                    || stripos((string) $product['category_name'], $query) !== false
            ));
        }
        if ($categoryId !== '' && ctype_digit($categoryId)) {
            $products = array_values(array_filter(
                $products,
                static fn (array $product): bool => (string) $product['category_id'] === $categoryId
            ));
        }
        if ($sort === 'new') {
            usort($products, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        } elseif ($sort === 'price_asc') {
            usort($products, static fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        } elseif ($sort === 'price_desc') {
            usort($products, static fn (array $a, array $b): int => $b['price'] <=> $a['price']);
        }

        return $this->renderPage('@northstar/shop.twig', [
            'products' => $products,
            'categories' => $this->stockflow->getCategories(),
            'selected_category' => $categoryId,
            'search_query' => $query,
            'sort' => $sort,
        ]);
    }

    public function product(Request $request, string $route_name, array $options): Response
    {
        $slug = $request->query->get('slug', '');
        if (!is_string($slug)) {
            $slug = '';
        }
        $product = null;
        foreach ($this->getCatalogProducts() as $candidate) {
            if ($candidate['slug'] === $slug) {
                $product = $candidate;
                break;
            }
        }
        if ($product === null) {
            return $this->renderPage('@northstar/not-found.twig', [], 404);
        }

        $selectedVariantId = null;
        foreach ($product['variants'] as $variant) {
            if ($variant['available_quantity'] > 0) {
                $selectedVariantId = $variant['id'];
                break;
            }
        }

        return $this->renderPage('@northstar/product.twig', [
            'product' => $product,
            'selected_variant_id' => $selectedVariantId,
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    public function cart(Request $request, string $route_name, array $options): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            try {
                $this->updateCart($request);
                return new RedirectResponse('/cart');
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        $cart = $this->getCartLines();
        return $this->renderPage('@northstar/cart.twig', [
            'cart' => $cart,
            'error' => $error,
            'csrf_token' => $this->csrfToken($request),
            'cart_has_stock' => $this->cartHasStock($cart),
            'subtotal' => $this->formatCartSubtotal($cart, $this->stockflow->getStoreSettings()['currency']),
        ]);
    }

    public function login(Request $request, string $route_name, array $options): Response
    {
        $destination = $this->accountDestination($request);
        $currentUser = $this->authenticatedUser();
        if ($currentUser !== null) {
            return new RedirectResponse($destination);
        }

        $values = [];
        $error = null;
        if ($request->isMethod('POST')) {
            $values = $request->request->all();
            $email = $values['email'] ?? '';
            $password = $values['password'] ?? '';
            if (!is_string($email) || !is_string($password) || trim($email) === '' || $password === '') {
                $error = 'Enter your email address and password.';
            } elseif (filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
                $error = 'Enter a valid email address.';
            } else {
                try {
                    $user = User::loadByEmail(trim($email), $this->database);
                    if (
                        $user === null
                        || !$user->verifyPassword($password)
                        || in_array($user->getStatus(), [User::STATUS_BANNED, User::STATUS_SUSPENDED], true)
                    ) {
                        $error = 'The email or password is incorrect.';
                    } else {
                        $settings = new Settings($this->database);
                        $twoFactor = $settings->getSetting(new TwoFactorSettings()->settingKey());
                        if ($twoFactor && $twoFactor->get('is_enabled') == 1) {
                            $providerKey = $twoFactor->get('two_factor_key');
                            if ($providerKey) {
                                $providerManager = new TwoFactorManager(getAppContainer()->get('plugin.manager'));
                                $provider = $providerManager->getTwofactorAuthenticationProvider($providerKey);
                                if ($provider !== null) {
                                    SessionStorage::add('two_factor_session', [
                                        'provider' => $provider->key(),
                                        'user' => $user->getId(),
                                    ]);
                                    return new RedirectResponse($provider->redirectLink());
                                }
                            }
                        }

                        if ($user->getId() === null) {
                            throw new \RuntimeException('The account has no user ID.');
                        }
                        $sessionId = session_id();
                        $session = new CurrentUser($this->database, getAppContainer()->get('logger'));
                        $session->setUserId($user->getId());
                        $session->setSessionId($sessionId);
                        $session->setIpAddress($request->getClientIp());
                        $session->setUserAgent($request->headers->get('User-Agent'));
                        $session->setExpiresAt((new DateTime())->add(new DateInterval('PT24H')));
                        $session->setUser($user);
                        $session->setUserData($user->toArray());
                        if (!$session->create()) {
                            throw new \RuntimeException('Could not start your account session.');
                        }

                        $response = new RedirectResponse($destination);
                        $response->headers->setCookie(new Cookie(
                            'session_id',
                            $sessionId,
                            new DateTime('+24 hours'),
                            '/',
                            null,
                            $request->isSecure(),
                            true
                        ));
                        return $response;
                    }
                } catch (\Throwable $exception) {
                    getAppContainer()->get('logger')->error('Northstar account sign-in failed', [
                        'email' => trim($email),
                        'exception' => $exception->getMessage(),
                    ]);
                    $error = 'We could not sign you in right now. Please try again.';
                }
            }
        }

        return $this->renderPage('@northstar/login.twig', [
            'destination' => $destination,
            'error' => $error,
            'message' => $request->query->get('message'),
            'values' => ['email' => is_string($values['email'] ?? null) ? $values['email'] : ''],
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    public function register(Request $request, string $route_name, array $options): Response
    {
        $destination = $this->accountDestination($request);
        $currentUser = $this->authenticatedUser();
        if ($currentUser !== null) {
            return new RedirectResponse($destination);
        }

        $values = [];
        $error = null;
        $accountCreated = false;
        if ($request->isMethod('POST')) {
            $values = $request->request->all();
            try {
                $username = $values['username'] ?? '';
                $email = $values['email'] ?? '';
                $firstName = $values['first_name'] ?? '';
                $lastName = $values['last_name'] ?? '';
                $password = $values['password'] ?? '';
                $passwordConfirm = $values['password_confirm'] ?? '';
                if (
                    !is_string($username)
                    || !is_string($email)
                    || !is_string($firstName)
                    || !is_string($lastName)
                    || !is_string($password)
                    || !is_string($passwordConfirm)
                ) {
                    throw new InvalidArgumentException('Enter valid account details.');
                }

                $username = trim($username);
                $email = strtolower(trim($email));
                $firstName = trim($firstName);
                $lastName = trim($lastName);
                if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
                    throw new InvalidArgumentException('Username must be 3–50 characters using letters, numbers, or underscores.');
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new InvalidArgumentException('Enter a valid email address.');
                }
                if ($firstName === '' || $lastName === '') {
                    throw new InvalidArgumentException('Enter your first and last name.');
                }
                if (strlen($password) < 8) {
                    throw new InvalidArgumentException('Your password must be at least 8 characters.');
                }
                if ($password !== $passwordConfirm) {
                    throw new InvalidArgumentException('The passwords do not match.');
                }
                if (User::loadByEmail($email, $this->database) !== null) {
                    throw new InvalidArgumentException('An account already exists for this email address.');
                }
                if (User::loadByUsername($username, $this->database) !== null) {
                    throw new InvalidArgumentException('That username is already taken.');
                }

                $user = new User([], $this->database, getAppContainer()->get('logger'));
                $user->setUsername($username);
                $user->setEmail($email);
                $user->setFirstName($firstName);
                $user->setLastName($lastName);
                $user->setPassword($password);
                $user->setRole('customer');
                $user->setStatus(User::STATUS_PENDING);
                if (!$user->save() || $user->getId() === null) {
                    throw new \RuntimeException('Your account could not be created. Please try again.');
                }
                $accountCreated = true;

                $verification = UserVerification::createEmailVerificationToken(
                    $this->database,
                    getAppContainer()->get('logger'),
                    (int) $user->getId(),
                    $email,
                    $request->getClientIp(),
                    $request->headers->get('User-Agent')
                );
                if ($verification === null) {
                    throw new \RuntimeException('Your account was created, but its verification email could not be prepared. Please contact the store.');
                }
                $mailContent = $this->renderTwig('@admin/emails/verify_email.twig', [
                    'user' => $user,
                    'verification_link' => $request->getSchemeAndHttpHost() . Url::routeByName(
                        'user.verify_email',
                        ['token' => $verification->getToken()]
                    ),
                ]);
                /** @var MailManager $mailManager */
                $mailManager = getAppContainer()->get('mail.manager');
                $mailManager->sendHtml(
                    $email,
                    $_ENV['MAIL_VERIFICATION_SUBJECT'] ?? 'Verify your email',
                    $mailContent->getContent()
                );

                return new RedirectResponse('/account/login?' . http_build_query([
                    'message' => 'registration_success',
                    'destination' => $destination,
                ]));
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (\Throwable $exception) {
                getAppContainer()->get('logger')->error('Northstar account registration failed', [
                    'email' => is_string($values['email'] ?? null) ? $values['email'] : null,
                    'exception' => $exception->getMessage(),
                ]);
                $error = $accountCreated
                    ? 'Your account was created, but we could not send the verification email. Please contact the store.'
                    : 'We could not create your account right now. Please try again.';
            }
        }

        foreach (['username', 'email', 'first_name', 'last_name'] as $field) {
            if (!is_string($values[$field] ?? null)) {
                $values[$field] = '';
            }
        }
        unset($values['password'], $values['password_confirm']);
        return $this->renderPage('@northstar/register.twig', [
            'destination' => $destination,
            'error' => $error,
            'values' => $values,
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    public function checkout(Request $request, string $route_name, array $options): Response
    {
        $user = $this->authenticatedUser();
        $userId = $user?->getId();
        if ($user === null || !is_numeric($userId) || (int) $userId < 1) {
            return new RedirectResponse('/account/login?destination=%2Fcheckout');
        }
        $userId = (int) $userId;
        $storedCheckoutData = SessionStorage::get(self::CHECKOUT_DATA_KEY);
        if (is_array($storedCheckoutData)) {
            $storedUserId = $storedCheckoutData['customer_user_id'] ?? null;
            if ($storedUserId !== null && (!is_numeric($storedUserId) || (int) $storedUserId !== $userId)) {
                $this->clearCheckoutState();
            } else {
                $storedCheckoutData['customer_user_id'] = $userId;
                SessionStorage::add(self::CHECKOUT_DATA_KEY, $storedCheckoutData);
            }
        }

        $cart = $this->getCartLines();
        if ($cart === []) {
            return new RedirectResponse('/cart');
        }

        $currency = $this->stockflow->getStoreSettings()['currency'];
        $shippingMethods = array_values(array_filter(
            $this->shipping->getCheckoutMethods(),
            static fn (array $method): bool => ($method['status'] ?? '') === 'active'
        ));
        $paymentMethods = array_values(array_filter(
            $this->paymentMethods->getMethods(),
            fn (array $method): bool =>
                $method['enabled']
                && $method['configured']
                && $this->paymentMethods->supportsCurrency((string) $method['id'], $currency)
        ));

        if ($request->isMethod('POST')) {
            $action = $request->request->get('_action');
            if ($action === 'details') {
                try {
                    if (!$this->cartHasStock($cart)) {
                        throw new InvalidArgumentException('Some cart quantities are no longer available. Update your cart and try again.');
                    }
                    $accountEmail = $user->getEmail();
                    if ($accountEmail === null) {
                        throw new InvalidArgumentException('Your account needs an email address before checkout.');
                    }
                    $orderData = $this->checkoutOrderData(
                        $request,
                        $cart,
                        $shippingMethods,
                        $userId,
                        $accountEmail
                    );
                    SessionStorage::add(self::CHECKOUT_DATA_KEY, $orderData);
                    SessionStorage::remove(self::CHECKOUT_REVIEW_KEY);
                    return $this->renderCheckoutPaymentForm(
                        $request,
                        $cart,
                        $shippingMethods,
                        $paymentMethods,
                        null,
                        $orderData
                    );
                } catch (InvalidArgumentException $exception) {
                    $values = $request->request->all();
                    $values['email'] = $user->getEmail() ?? '';
                    return $this->renderCheckoutForm(
                        $request,
                        $cart,
                        $shippingMethods,
                        $paymentMethods,
                        $exception->getMessage(),
                        $values
                    );
                }
            }

            if ($action === 'review') {
                $orderData = SessionStorage::get(self::CHECKOUT_DATA_KEY);
                if (!is_array($orderData) || empty($orderData['email'])) {
                    return $this->renderCheckoutForm(
                        $request,
                        $cart,
                        $shippingMethods,
                        $paymentMethods,
                        'Enter your delivery details before continuing.',
                        []
                    );
                }
                try {
                    if (!$this->cartHasStock($cart)) {
                        throw new InvalidArgumentException('Some cart quantities are no longer available. Update your cart and try again.');
                    }
                    $orderData = $this->checkoutPaymentData($request, $orderData, $paymentMethods);
                    $this->paymentMethods->validateCharge(
                        $orderData['payment_method'],
                        $currency,
                        $orderData['payment_data']
                    );
                    $review = $this->orders->previewOrder(
                        $orderData,
                        $this->adjustments->getRules('taxes', true),
                        $this->adjustments->getRules('discounts', true)
                    );
                    $payload = $this->reviewPayload($review);
                    $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                    SessionStorage::add(self::CHECKOUT_DATA_KEY, $orderData);
                    SessionStorage::add(self::CHECKOUT_REVIEW_KEY, [
                        'payload' => $payload,
                        'hash' => $hash,
                    ]);
                    return $this->renderCheckoutReview(
                        $request,
                        $payload,
                        $hash,
                        $shippingMethods,
                        $paymentMethods,
                        null
                    );
                } catch (InvalidArgumentException $exception) {
                    return $this->renderCheckoutPaymentForm(
                        $request,
                        $cart,
                        $shippingMethods,
                        $paymentMethods,
                        $exception->getMessage(),
                        $this->checkoutPaymentFormValues($request, $orderData)
                    );
                } catch (\Throwable $exception) {
                    $this->log('Northstar could not prepare the checkout review', [], $exception);
                    return $this->renderCheckoutPaymentForm(
                        $request,
                        $cart,
                        $shippingMethods,
                        $paymentMethods,
                        'Checkout is temporarily unavailable. Please try again.',
                        $this->checkoutPaymentFormValues($request, $orderData)
                    );
                }
            }

            if ($action === 'edit_details') {
                $orderData = SessionStorage::get(self::CHECKOUT_DATA_KEY);
                SessionStorage::remove(self::CHECKOUT_REVIEW_KEY);
                return $this->renderCheckoutForm(
                    $request,
                    $cart,
                    $shippingMethods,
                    $paymentMethods,
                    null,
                    is_array($orderData) ? $orderData : []
                );
            }

            if ($action === 'confirm') {
                return $this->confirmCheckout(
                    $request,
                    $cart,
                    $currency,
                    $shippingMethods,
                    $paymentMethods
                );
            }
            return $this->renderCheckoutPaymentForm(
                $request,
                $cart,
                $shippingMethods,
                $paymentMethods,
                'This checkout action is not recognized.',
                $request->request->all()
            );
        }

        $existingOrderData = SessionStorage::get(self::CHECKOUT_DATA_KEY);
        if (!is_array($existingOrderData)) {
            $existingOrderData = [];
        }
        $hasDeliveryDetails = isset(
            $existingOrderData['customer_user_id'],
            $existingOrderData['shipping_method'],
            $existingOrderData['items']
        ) && (int) $existingOrderData['customer_user_id'] === $userId
            && is_array($existingOrderData['items']);
        if (empty($existingOrderData['email'])) {
            $existingOrderData['email'] = $user->getEmail() ?? '';
            $existingOrderData['customer_first_name'] = $user->getFirstName() ?? '';
            $existingOrderData['customer_last_name'] = $user->getLastName() ?? '';
        }
        if ($hasDeliveryDetails) {
            return $this->renderCheckoutPaymentForm(
                $request,
                $cart,
                $shippingMethods,
                $paymentMethods,
                null,
                $existingOrderData
            );
        }
        return $this->renderCheckoutForm(
            $request,
            $cart,
            $shippingMethods,
            $paymentMethods,
            null,
            $existingOrderData
        );
    }

    public function confirmation(Request $request, string $route_name, array $options): Response
    {
        $confirmation = SessionStorage::get(self::CONFIRMATION_KEY);
        if (!is_array($confirmation)) {
            return new RedirectResponse('/shop');
        }
        return $this->renderPage('@northstar/confirmation.twig', [
            'confirmation' => $confirmation,
        ]);
    }

    public function account(Request $request, string $route_name, array $options): Response
    {
        $user = $this->authenticatedUser();
        if ($user === null) {
            return new RedirectResponse('/account/login?destination=%2Faccount');
        }

        $orders = [];
        if (
            $user->hasPermission('db.stockflow.storefront')
            || in_array($user->getRole(), ['admin', 'super_admin'], true)
        ) {
            $orders = $this->database->table('orders', 'stockflow')
                ->select([
                    'orders.id',
                    'orders.order_number',
                    'orders.status',
                    'orders.grand_total',
                    'orders.currency_code',
                    'orders.placed_at',
                ])
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->where('customers.user_id', '=', (int) $user->getId())
                ->orderBy('orders.placed_at', 'DESC')
                ->limit(20)
                ->get();
            foreach ($orders as &$order) {
                $order['display_total'] = (new Price(
                    (float) $order['grand_total'],
                    (string) $order['currency_code']
                ))->format();
                $orderDetails = [
                    'status' => $order['status'],
                    'payments' => $this->orders->getOrderPayments((int) $order['id']),
                ];
                $order['payment_status'] = $this->hasPendingPayment($orderDetails)
                    ? 'pending'
                    : ($orderDetails['payments'][0]['status'] ?? '');
                $order['can_pay'] = $this->authorizeNetAvailable((string) $order['currency_code'])
                    && $this->orderCanBePaidByAuthorizeNet($orderDetails);
                $order['payment_due'] = $order['status'] === 'pending'
                    && $order['payment_status'] === 'pending';
            }
            unset($order);
        }

        $paymentMessage = $request->query->get('payment_message');
        return $this->renderPage('@northstar/account.twig', [
            'orders' => $orders,
            'addresses' => $this->customerAddresses((int) $user->getId(), $user->getEmail()),
            'user_name' => $user->getFullName() ?? $user->getDisplayName() ?? '',
            'payment_message' => is_string($paymentMessage) ? $paymentMessage : null,
            'payment_contact_email' => $this->paymentContactEmail(),
        ]);
    }

    public function accountOrder(Request $request, string $route_name, array $options): Response
    {
        $user = $this->authenticatedUser();
        if ($user === null) {
            return new RedirectResponse('/account/login?destination=%2Faccount');
        }
        if (
            !$user->hasPermission('db.stockflow.storefront')
            && !in_array($user->getRole(), ['admin', 'super_admin'], true)
        ) {
            return new RedirectResponse('/account');
        }

        $orderId = filter_var($request->query->get('id'), FILTER_VALIDATE_INT);
        if ($orderId === false || $orderId < 1) {
            return new RedirectResponse('/account');
        }
        $ownedOrder = $this->database->table('orders', 'stockflow')
            ->select(['orders.id'])
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->where('orders.id', '=', $orderId)
            ->where('customers.user_id', '=', (int) $user->getId())
            ->first();
        if ($ownedOrder === null) {
            return new RedirectResponse('/account');
        }

        $order = $this->orders->getOrder((int) $orderId);
        if ($order === null) {
            return new RedirectResponse('/account');
        }
        $order['payment_status'] = $this->hasPendingPayment($order)
            ? 'pending'
            : ($order['payments'][0]['status'] ?? '');
        $order['can_pay'] = $this->authorizeNetAvailable((string) $order['currency_code'])
            && $this->orderCanBePaidByAuthorizeNet($order);
            
        $order['payment_due'] = $order['status'] === 'pending'
            && $order['payment_status'] === 'pending';
        return $this->renderPage('@northstar/account-order.twig', [
            'order' => $order,
            'payment_contact_email' => $this->paymentContactEmail(),
        ]);
    }

    public function accountOrderPayment(Request $request, string $route_name, array $options): Response
    {
        $user = $this->authenticatedUser();
        if ($user === null) {
            return new RedirectResponse('/account/login?destination=%2Faccount');
        }
        if (
            !$user->hasPermission('db.stockflow.storefront')
            && !in_array($user->getRole(), ['admin', 'super_admin'], true)
        ) {
            return new RedirectResponse('/account');
        }

        $orderId = filter_var($request->query->get('id'), FILTER_VALIDATE_INT);
        if ($orderId === false || $orderId < 1) {
            return new RedirectResponse('/account');
        }
        $ownedOrder = $this->database->table('orders', 'stockflow')
            ->select(['orders.id'])
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->where('orders.id', '=', $orderId)
            ->where('customers.user_id', '=', (int) $user->getId())
            ->first();
        if ($ownedOrder === null) {
            return new RedirectResponse('/account');
        }

        $order = $this->orders->getOrder((int) $orderId);
        if ($order === null || !$this->orderCanBePaidByAuthorizeNet($order)) {
            return new RedirectResponse('/account?payment_message=This+order+is+not+awaiting+an+eligible+Authorize.Net+payment.');
        }
        if (!$this->authorizeNetAvailable((string) $order['currency_code'])) {
            return new RedirectResponse('/account?payment_message=Authorize.Net+payments+are+currently+unavailable.');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $paymentData = $request->request->all()['payment_data'] ?? null;
            if (
                !is_array($paymentData)
                || !is_string($paymentData['opaque_data_descriptor'] ?? null)
                || !is_string($paymentData['opaque_data_value'] ?? null)
            ) {
                $error = 'Enter your card details to continue.';
            } else {
                $paymentData = [
                    'opaque_data_descriptor' => $paymentData['opaque_data_descriptor'],
                    'opaque_data_value' => $paymentData['opaque_data_value'],
                    'order_number' => (string) $order['order_number'],
                ];
                try {
                    $this->paymentMethods->validateCharge(
                        'authorize_net',
                        (string) $order['currency_code'],
                        $paymentData
                    );
                    $result = $this->paymentMethods->charge(
                        'authorize_net',
                        new Price((float) $order['grand_total'], (string) $order['currency_code']),
                        (string) $order['currency_code'],
                        $paymentData
                    );
                    $this->orders->recordPaymentResult((int) $order['id'], $result);
                    $message = match ($result['status'] ?? '') {
                        'paid' => 'Payment received. Thank you for your order.',
                        'authorized' => 'Your payment was authorized and is being processed.',
                        'failed' => 'Payment was declined. The order is no longer awaiting payment.',
                        default => 'Your payment is still processing. Check your order history for updates.',
                    };
                    return new RedirectResponse('/account?' . http_build_query([
                        'payment_message' => $message,
                    ]));
                } catch (InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                } catch (\Throwable $exception) {
                    $this->log('Northstar account order payment failed', $order, $exception);
                    $error = 'We could not confirm the payment result. Check your order history before trying again.';
                }
            }
        }

        $authorizeClient = $this->paymentMethods->authorizeNetClientConfiguration();
        return $this->renderPage('@northstar/account-payment.twig', [
            'order' => $order,
            'error' => $error,
            'csrf_token' => $this->csrfToken($request),
            'authorize_client' => $authorizeClient,
        ]);
    }

    private function orderCanBePaidByAuthorizeNet(array $order): bool
    {
        if (($order['status'] ?? '') !== 'pending') {
            return false;
        }
        $payments = $order['payments'] ?? [];
        return $this->hasPendingAuthorizeNetPayment(['payments' => $payments]);
    }

    private function hasPendingAuthorizeNetPayment(array $order): bool
    {
        $flag = true;
        foreach ($order['payments'] ?? [] as $payment) {
            if (($payment['status'] ?? '') === 'paid') {
                $flag = false;
            }
        }
        return $flag;
    }

    private function hasPendingPayment(array $order): bool
    {
        foreach ($order['payments'] ?? [] as $payment) {
            if (($payment['status'] ?? '') === 'pending') {
                return true;
            }
        }
        return false;
    }

    private function authorizeNetEnabledForCurrency(string $currency): bool
    {
        foreach ($this->paymentMethods->getMethods() as $method) {
            if (($method['id'] ?? '') === 'authorize_net') {
                return ($method['enabled'] ?? false)
                    && $this->paymentMethods->supportsCurrency('authorize_net', $currency);
            }
        }
        return false;
    }

    private function paymentContactEmail(): string
    {
        $email = $_ENV['MAIL_FROM_EMAIL'] ?? getenv('MAIL_FROM_EMAIL') ?: '';
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : '';
    }

    private function authorizeNetAvailable(?string $currency = null): bool
    {
        foreach ($this->paymentMethods->getMethods() as $method) {
            if (($method['id'] ?? '') === 'authorize_net') {
                return ($method['enabled'] ?? false)
                    && ($method['configured'] ?? false)
                    && ($currency === null || $this->paymentMethods->supportsCurrency('authorize_net', $currency));
            }
        }
        return false;
    }

    private function confirmCheckout(
        Request $request,
        array $cart,
        string $currency,
        array $shippingMethods,
        array $paymentMethods
    ): Response {
        $storedData = SessionStorage::get(self::CHECKOUT_DATA_KEY);
        $storedReview = SessionStorage::get(self::CHECKOUT_REVIEW_KEY);
        $submittedHash = $request->request->get('review_hash');
        if (
            !is_array($storedData)
            || !is_array($storedReview)
            || !is_string($submittedHash)
            || !hash_equals((string) ($storedReview['hash'] ?? ''), $submittedHash)
        ) {
            $values = is_array($storedData) ? $storedData : [];
            if (empty($values['email'])) {
                return $this->renderCheckoutForm(
                    $request,
                    $cart,
                    $shippingMethods,
                    $paymentMethods,
                    'Your checkout review expired. Please enter your delivery details again.',
                    $values
                );
            }
            return $this->renderCheckoutPaymentForm(
                $request,
                $cart,
                $shippingMethods,
                $paymentMethods,
                'Your checkout review expired. Please review your order again.',
                $values
            );
        }

        try {
            $review = $this->orders->previewOrder(
                $storedData,
                $this->adjustments->getRules('taxes', true),
                $this->adjustments->getRules('discounts', true)
            );
            $payload = $this->reviewPayload($review);
            $freshHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if (!hash_equals((string) $storedReview['hash'], $freshHash)) {
                SessionStorage::add(self::CHECKOUT_REVIEW_KEY, [
                    'payload' => $payload,
                    'hash' => $freshHash,
                ]);
                return $this->renderCheckoutReview(
                    $request,
                    $payload,
                    $freshHash,
                    $shippingMethods,
                    $paymentMethods,
                    'Your order totals or availability changed. Please review the updated order before confirming.'
                );
            }

            $this->paymentMethods->validateCharge(
                (string) $storedData['payment_method'],
                $currency,
                $storedData['payment_data'] ?? []
            );
            $storedData = $this->prepareNorthstarCheckoutCustomerAddress($storedData);
            SessionStorage::add(self::CHECKOUT_DATA_KEY, $storedData);
            $order = $this->orders->createCheckoutOrder(
                $storedData,
                $this->adjustments->getRules('taxes', true),
                $this->adjustments->getRules('discounts', true)
            );
            $paymentStatus = 'pending';
            $paymentMessage = 'Your order has been received. Payment is due according to the selected payment method.';
            $cartShouldBeCleared = true;

            if (!$this->paymentMethods->isOffline($order['payment_method'])) {
                try {
                    $result = $this->paymentMethods->charge(
                        $order['payment_method'],
                        $order['amount'],
                        $order['currency'],
                        array_merge(
                            $order['payment_data'] ?? [],
                            ['order_number' => $order['order_number']]
                        )
                    );
                } catch (InvalidArgumentException $exception) {
                    $this->log('Northstar payment submission failed', $order, $exception);
                    try {
                        $this->orders->recordPaymentResult((int) $order['id'], [
                            'status' => 'failed',
                            'provider_payment_id' => null,
                        ]);
                        $paymentStatus = 'failed';
                        $paymentMessage = $exception->getMessage();
                        $cartShouldBeCleared = false;
                    } catch (\Throwable $recordException) {
                        $paymentMessage = 'Your order was saved, but payment status could not be confirmed. Contact the store before trying to pay again.';
                        $this->log('Northstar could not record a failed payment', $order, $recordException);
                    }
                } catch (\Throwable $exception) {
                    $paymentStatus = 'pending';
                    $paymentMessage = 'Your order was saved, but payment status could not be confirmed. Contact the store before trying to pay again.';
                    $this->log('Northstar payment result could not be confirmed', $order, $exception);
                }

                if (isset($result)) {
                    try {
                        $this->orders->recordPaymentResult((int) $order['id'], $result);
                        $paymentStatus = (string) ($result['status'] ?? 'pending');
                        $paymentMessage = match ($paymentStatus) {
                            'paid' => 'Payment received. Thank you for your order.',
                            'failed' => 'Payment was declined. Your cart is ready if you would like to try again.',
                            'authorized' => 'Payment was authorized. The store will finalize your order shortly.',
                            default => 'Your payment request is pending. We will update you when it is complete.',
                        };
                        $cartShouldBeCleared = $paymentStatus !== 'failed';
                    } catch (\Throwable $exception) {
                        $paymentStatus = 'pending';
                        $paymentMessage = 'Your order was saved, but payment status could not be confirmed. Contact the store before trying to pay again.';
                        $this->log('Northstar payment result could not be saved', $order, $exception);
                    }
                }
            }

            if ($cartShouldBeCleared) {
                $this->removePurchasedCartItems($storedData);
            }
            SessionStorage::remove(self::CHECKOUT_DATA_KEY);
            SessionStorage::remove(self::CHECKOUT_REVIEW_KEY);
            SessionStorage::add(self::CONFIRMATION_KEY, [
                'order_number' => $order['order_number'],
                'email' => $storedData['email'],
                'total' => $order['amount']->format(),
                'currency' => $order['currency'],
                'payment_status' => $paymentStatus,
                'message' => $paymentMessage,
            ]);
            return new RedirectResponse('/checkout/confirmation');
        } catch (InvalidArgumentException $exception) {
            return $this->renderCheckoutPaymentForm(
                $request,
                $cart,
                $shippingMethods,
                $paymentMethods,
                $exception->getMessage(),
                $storedData
            );
        } catch (\Throwable $exception) {
            $this->log('Northstar checkout failed', [], $exception);
            return $this->renderCheckoutPaymentForm(
                $request,
                $cart,
                $shippingMethods,
                $paymentMethods,
                'Checkout could not be completed. Please try again.',
                $storedData
            );
        }
    }

    private function renderCheckoutForm(
        Request $request,
        array $cart,
        array $shippingMethods,
        array $paymentMethods,
        ?string $error,
        array $values
    ): Response {
        $currency = $this->stockflow->getStoreSettings()['currency'];
        $user = $this->authenticatedUser();
        $addresses = $user?->getId() !== null
            ? $this->customerAddresses((int) $user->getId(), $user->getEmail())
            : [];
        $emailName = $user?->getEmail() !== null
            ? trim((string) strstr((string) $user->getEmail(), '@', true))
            : '';
        $values['customer_first_name'] = trim((string) ($values['customer_first_name'] ?? ''))
            ?: (trim((string) ($user?->getFirstName() ?? '')) ?: (ucfirst($emailName) ?: 'Customer'));
        $values['customer_last_name'] = trim((string) ($values['customer_last_name'] ?? ''))
            ?: (trim((string) ($user?->getLastName() ?? '')) ?: 'Customer');
        if (
            $addresses !== []
            && empty($values['saved_address_id'])
            && empty($values['billing_line1'])
        ) {
            foreach ($addresses as $address) {
                if ($address['is_default']) {
                    $values['saved_address_id'] = (string) $address['id'];
                    break;
                }
            }
            $values['saved_address_id'] ??= (string) $addresses[0]['id'];
        }
        $values['saved_address_id'] ??= isset($values['billing_address_id'])
            ? (string) $values['billing_address_id']
            : 'new';

        return $this->renderPage('@northstar/checkout.twig', [
            'cart' => $cart,
            'addresses' => $addresses,
            'shipping_methods' => $shippingMethods,
            'payment_methods' => $paymentMethods,
            'values' => $values,
            'error' => $error,
            'csrf_token' => $this->csrfToken($request),
            'currency' => $currency,
            'subtotal' => $this->formatCartSubtotal($cart, $currency),
        ]);
    }

    private function renderCheckoutPaymentForm(
        Request $request,
        array $cart,
        array $shippingMethods,
        array $paymentMethods,
        ?string $error,
        array $values
    ): Response {
        $currency = $this->stockflow->getStoreSettings()['currency'];
        $authorizeClient = null;
        foreach ($paymentMethods as $method) {
            if (($method['id'] ?? '') === 'authorize_net') {
                try {
                    $authorizeClient = $this->paymentMethods->authorizeNetClientConfiguration();
                } catch (\Throwable $exception) {
                    $this->log('Northstar could not load Authorize.Net client configuration', [], $exception);
                    $paymentMethods = array_values(array_filter(
                        $paymentMethods,
                        static fn (array $candidate): bool => ($candidate['id'] ?? '') !== 'authorize_net'
                    ));
                    $error ??= 'Credit card checkout is temporarily unavailable. Choose another payment method or contact the store.';
                }
                break;
            }
        }

        $shippingMethod = (string) ($values['shipping_method'] ?? 'pickup');
        $shippingName = $shippingMethod;
        foreach ($shippingMethods as $method) {
            if ((string) $method['id'] === $shippingMethod) {
                $shippingName = (string) $method['name'];
                break;
            }
        }

        return $this->renderPage('@northstar/checkout-payment.twig', [
            'cart' => $cart,
            'payment_methods' => $paymentMethods,
            'values' => $values,
            'error' => $error,
            'csrf_token' => $this->csrfToken($request),
            'authorize_client' => $authorizeClient,
            'subtotal' => $this->formatCartSubtotal($cart, $currency),
            'shipping_name' => $shippingName,
            'delivery' => ($values['shipping_method'] ?? 'pickup') !== 'pickup',
        ]);
    }

    private function renderCheckoutReview(
        Request $request,
        array $review,
        string $hash,
        array $shippingMethods,
        array $paymentMethods,
        ?string $notice
    ): Response {
        return $this->renderPage('@northstar/checkout-review.twig', [
            'review' => $review,
            'review_hash' => $hash,
            'shipping_methods' => $shippingMethods,
            'payment_methods' => $paymentMethods,
            'notice' => $notice,
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    private function checkoutOrderData(
        Request $request,
        array $cart,
        array $shippingMethods,
        int $userId,
        string $accountEmail
    ): array
    {
        $form = $request->request->all();
        if ($cart === []) {
            throw new InvalidArgumentException('Your cart is empty.');
        }

        $email = $this->formString($form, 'email', 'Email address');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid customer email address.');
        }
        if (strcasecmp($email, $accountEmail) !== 0) {
            throw new InvalidArgumentException('Use the email address on your signed-in account for checkout.');
        }
        $accountName = trim((string) strstr($accountEmail, '@', true));
        $accountName = $accountName !== '' ? ucfirst($accountName) : 'Customer';
        $firstName = is_string($form['first_name'] ?? null) && trim($form['first_name']) !== ''
            ? trim($form['first_name'])
            : $accountName;
        $lastName = is_string($form['last_name'] ?? null) && trim($form['last_name']) !== ''
            ? trim($form['last_name'])
            : 'Customer';
        $phone = $form['phone'] ?? '';
        if (!is_string($phone)) {
            throw new InvalidArgumentException('Phone must be a single value.');
        }
        $shippingMethod = $this->formString($form, 'shipping_method', 'Shipping method');
        $shippingIsValid = false;
        foreach ($shippingMethods as $method) {
            if ((string) $method['id'] === $shippingMethod) {
                $shippingIsValid = true;
                break;
            }
        }
        if (!$shippingIsValid) {
            throw new InvalidArgumentException('Select a valid shipping method.');
        }

        $orderData = [
            'email' => $accountEmail,
            'customer_user_id' => $userId,
            'customer_first_name' => $firstName,
            'customer_last_name' => $lastName,
            'customer_phone' => trim($phone),
            'payment_method' => '',
            'payment_data' => [],
            'items' => array_map(static fn (array $line): array => [
                'variant_id' => (int) $line['variant']['id'],
                'quantity' => (int) $line['quantity'],
            ], $cart),
            'shipping_method' => $shippingMethod,
            'order_tax_ids' => [],
            'order_discount_id' => '',
            'same_shipping' => 'yes',
        ];
        $savedAddressId = $form['saved_address_id'] ?? 'new';
        if (!is_string($savedAddressId)) {
            throw new InvalidArgumentException('Select a valid delivery address.');
        }
        $addressFields = [
            'company', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code', 'phone',
        ];
        $addressProvided = $shippingMethod !== 'pickup';
        $hasAddressInput = false;
        foreach ($addressFields as $field) {
            $value = $form['address_' . $field] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('Address fields must be single values.');
            }
            $hasAddressInput = $hasAddressInput || trim($value) !== '';
        }
        $useAddressDefaults = $shippingMethod !== 'pickup' || $hasAddressInput;
        if ($shippingMethod !== 'pickup' && $savedAddressId !== 'new' && $savedAddressId !== '') {
            $addressId = filter_var($savedAddressId, FILTER_VALIDATE_INT);
            $savedAddress = $addressId === false
                ? null
                : $this->findCustomerAddress($userId, $addressId, $accountEmail);
            if ($savedAddress === null) {
                throw new InvalidArgumentException('Select one of your saved addresses or enter a new address.');
            }
            foreach ($addressFields as $field) {
                $orderData['billing_' . $field] = (string) ($savedAddress[$field] ?? '');
            }
            $orderData['billing_first_name'] = trim((string) ($savedAddress['first_name'] ?? '')) !== ''
                ? (string) $savedAddress['first_name']
                : $firstName;
            $orderData['billing_last_name'] = trim((string) ($savedAddress['last_name'] ?? '')) !== ''
                ? (string) $savedAddress['last_name']
                : $lastName;
            $orderData['billing_address_id'] = (int) $savedAddress['id'];
            $orderData['saved_address_id'] = (int) $savedAddress['id'];
        } else {
            foreach ($addressFields as $field) {
                $value = $form['address_' . $field] ?? '';
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Address fields must be single values.');
                }
                $value = trim($value);
                $orderData['billing_' . $field] = !$useAddressDefaults ? $value : match ($field) {
                    'line1' => $value !== '' ? $value : 'Not provided',
                    'city' => $value !== '' ? $value : 'Not provided',
                    'postal_code' => $value !== '' ? $value : '00000',
                    'country_code' => strtoupper($value !== '' ? $value : 'MW'),
                    default => $value,
                };
                $addressProvided = $addressProvided || $value !== '';
            }
            $orderData['billing_first_name'] = $addressProvided ? $orderData['customer_first_name'] : '';
            $orderData['billing_last_name'] = $addressProvided ? $orderData['customer_last_name'] : '';
            $orderData['saved_address_id'] = 'new';
        }
        if ($addressProvided && $orderData['billing_phone'] === '') {
            $orderData['billing_phone'] = $orderData['customer_phone'];
        }
        if ($shippingMethod !== 'pickup') {
            foreach (['line1', 'city', 'postal_code', 'country_code'] as $requiredField) {
                if ($orderData['billing_' . $requiredField] === '') {
                    throw new InvalidArgumentException('Complete the shipping address before continuing.');
                }
            }
            if (!preg_match('/^[A-Z]{2}$/', strtoupper($orderData['billing_country_code']))) {
                throw new InvalidArgumentException('Country must be a two-letter ISO code.');
            }
        }
        return $orderData;
    }

    private function customerAddresses(int $userId, ?string $email = null): array
    {
        $customers = $this->database->table('customers', 'stockflow')
            ->select(['id'])
            ->where('user_id', '=', $userId)
            ->get();
        if (is_string($email) && $email !== '') {
            $unlinkedCustomers = $this->database->table('customers', 'stockflow')
                ->select(['id'])
                ->where('email', '=', $email)
                ->where('user_id', 'IS NULL')
                ->get();
            $customers = array_merge($customers, $unlinkedCustomers);
        }
        $addresses = [];
        foreach ($customers as $customer) {
            $rows = $this->database->table('addresses', 'stockflow')
                ->select([
                    'id',
                    'customer_id',
                    'label',
                    'first_name',
                    'last_name',
                    'company',
                    'line1',
                    'line2',
                    'city',
                    'state',
                    'postal_code',
                    'country_code',
                    'phone',
                    'is_default',
                ])
                ->where('customer_id', '=', (int) $customer['id'])
                ->orderBy('is_default', 'DESC')
                ->orderBy('id', 'DESC')
                ->get();
            foreach ($rows as $address) {
                $address['id'] = (int) $address['id'];
                $address['is_default'] = (bool) $address['is_default'];
                $addresses[$address['id']] = $address;
            }
        }
        $userAddresses = $this->database->table('addresses', 'stockflow')
            ->select([
                'id',
                'customer_id',
                'label',
                'first_name',
                'last_name',
                'company',
                'line1',
                'line2',
                'city',
                'state',
                'postal_code',
                'country_code',
                'phone',
                'is_default',
            ])
            ->where('user_id', '=', $userId)
            ->orderBy('is_default', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();
        foreach ($userAddresses as $address) {
            $address['id'] = (int) $address['id'];
            $address['is_default'] = (bool) $address['is_default'];
            $addresses[$address['id']] = $address;
        }
        $addresses = array_values($addresses);
        usort($addresses, static fn (array $first, array $second): int =>
            ($second['is_default'] <=> $first['is_default']) ?: ($second['id'] <=> $first['id'])
        );
        return $addresses;
    }

    private function findCustomerAddress(int $userId, int $addressId, ?string $email = null): ?array
    {
        foreach ($this->customerAddresses($userId, $email) as $address) {
            if ($address['id'] === $addressId) {
                return $address;
            }
        }
        return null;
    }

    private function checkoutPaymentData(Request $request, array $orderData, array $availableMethods): array
    {
        $form = $request->request->all();
        $paymentMethodId = $this->formString($form, 'payment_method', 'Payment method');
        $paymentMethod = null;
        foreach ($availableMethods as $method) {
            if (($method['id'] ?? '') === $paymentMethodId) {
                $paymentMethod = $method;
                break;
            }
        }
        if ($paymentMethod === null) {
            throw new InvalidArgumentException('Select an available payment method.');
        }

        $submittedPaymentData = $form['payment_data'] ?? [];
        if (!is_array($submittedPaymentData)) {
            throw new InvalidArgumentException('Payment details must be valid.');
        }
        $allowedPaymentFields = array_unique(array_merge(
            $paymentMethod['required_payment_data'] ?? [],
            array_column($paymentMethod['payment_fields'] ?? [], 'name')
        ));
        $paymentData = array_intersect_key(
            $submittedPaymentData,
            array_fill_keys($allowedPaymentFields, true)
        );
        foreach ($paymentData as $key => $value) {
            if (!is_string($value) || strlen($value) > 4000) {
                throw new InvalidArgumentException('Payment details must be valid.');
            }
            $paymentData[$key] = trim($value);
        }

        $orderData['payment_method'] = $paymentMethodId;
        $orderData['payment_data'] = $paymentData;
        return $orderData;
    }

    private function checkoutPaymentFormValues(Request $request, array $orderData): array
    {
        $submitted = $request->request->all();
        return array_merge($orderData, [
            'payment_method' => is_string($submitted['payment_method'] ?? null)
                ? $submitted['payment_method']
                : '',
            'payment_data' => is_array($submitted['payment_data'] ?? null)
                ? $submitted['payment_data']
                : [],
        ]);
    }

    private function prepareNorthstarCheckoutCustomerAddress(array $checkoutData): array
    {
        
        $userId = filter_var($checkoutData['customer_user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            throw new \RuntimeException('Checkout data is missing the signed-in customer account.');
        }

        $email = trim((string) ($checkoutData['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid account email before checkout.');
        }
        $firstName = trim((string) ($checkoutData['customer_first_name'] ?? '')) ?: 'Customer';
        $lastName = trim((string) ($checkoutData['customer_last_name'] ?? '')) ?: 'Customer';
        $phone = trim((string) ($checkoutData['customer_phone'] ?? ''));

        $customer = $this->database->table('customers', 'stockflow')
            ->where('user_id', '=', $userId)
            ->first();
        if ($customer === null) {
            $customer = $this->database->table('customers', 'stockflow')
                ->where('email', '=', $email)
                ->first();
        }
        if ($customer !== null && $customer['status'] === 'blocked') {
            throw new InvalidArgumentException('Orders cannot be created for a blocked customer.');
        }
        if ($customer !== null && $customer['user_id'] !== null && (int) $customer['user_id'] !== $userId) {
            throw new InvalidArgumentException('This customer profile is linked to another account.');
        }
        if ($customer === null) {
            $customerId = $this->database->table('customers', 'stockflow')->insert([
                'user_id' => $userId,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone !== '' ? $phone : null,
                'status' => 'active',
                'accepts_marketing' => 0,
            ]);
        } else {
            $customerId = (int) $customer['id'];
            $this->database->table('customers', 'stockflow')
                ->where('id', '=', $customerId)
                ->update([
                    'user_id' => $userId,
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone !== '' ? $phone : null,
                    'status' => 'active',
                ]);
            $linkedCustomer = $this->database->table('customers', 'stockflow')
                ->select(['id', 'user_id'])
                ->where('id', '=', $customerId)
                ->first();
            if ($linkedCustomer === null || (int) $linkedCustomer['user_id'] !== $userId) {
                throw new \RuntimeException('The customer profile could not be linked to the signed-in user.');
            }
        }

        $checkoutData['customer_user_id'] = $userId;
        $delivery = (string) ($checkoutData['shipping_method'] ?? '') !== 'pickup';
        $existingAddressId = filter_var($checkoutData['billing_address_id'] ?? null, FILTER_VALIDATE_INT);
        if ($existingAddressId !== false && $existingAddressId > 0) {
            $address = $this->database->table('addresses', 'stockflow')
                ->where('id', '=', $existingAddressId)
                ->first();
            $addressCustomerId = (int) ($address['customer_id'] ?? 0);
            $addressUserId = (int) ($address['user_id'] ?? 0);
            if (
                $address === null
                || ($addressCustomerId !== 0 && $addressCustomerId !== $customerId)
                || ($addressUserId !== 0 && $addressUserId !== $userId)
                || ($addressCustomerId !== $customerId && $addressUserId !== $userId)
            ) {
                throw new InvalidArgumentException('The selected address is not linked to your customer profile.');
            }
            return $checkoutData;
        }

        $addressId = $this->database->table('addresses', 'stockflow')->insert([
            'user_id' => $userId,
            'customer_id' => $customerId,
            'label' => 'Order address',
            'first_name' => trim((string) ($checkoutData['billing_first_name'] ?? '')) ?: $firstName,
            'last_name' => trim((string) ($checkoutData['billing_last_name'] ?? '')) ?: $lastName,
            'company' => (string) ($checkoutData['billing_company'] ?? ''),
            'line1' => trim((string) ($checkoutData['billing_line1'] ?? '')) ?: 'Not provided',
            'line2' => (string) ($checkoutData['billing_line2'] ?? ''),
            'city' => trim((string) ($checkoutData['billing_city'] ?? '')) ?: 'Not provided',
            'state' => (string) ($checkoutData['billing_state'] ?? ''),
            'postal_code' => trim((string) ($checkoutData['billing_postal_code'] ?? '')) ?: '00000',
            'country_code' => strtoupper(trim((string) ($checkoutData['billing_country_code'] ?? ''))) ?: 'MW',
            'phone' => trim((string) ($checkoutData['billing_phone'] ?? '')) ?: ($phone !== '' ? $phone : null),
            'is_default' => 0,
        ]);
        $checkoutData['billing_address_id'] = $addressId;
        $checkoutData['saved_address_id'] = (string) $addressId;
        return $checkoutData;
    }

    private function reviewPayload(array $review): array
    {
        $money = static fn (Price $price): string => number_format($price->getAmount(), 2, '.', '');
        return [
            'currency' => (string) $review['currency'],
            'customer' => $review['customer'],
            'billing_address' => $review['billing_address'],
            'shipping_address' => $review['shipping_address'],
            'payment_method' => (string) $review['payment_method'],
            'shipping_method' => (string) $review['shipping_method'],
            'items' => array_map(static fn (array $item): array => [
                'variant_id' => (int) $item['variant_id'],
                'name' => (string) $item['name'],
                'sku' => (string) $item['sku'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => $money($item['unit_price']),
                'total' => $money($item['total']),
            ], $review['items']),
            'subtotal' => $money($review['subtotal']),
            'discount' => $money($review['discount']),
            'shipping' => $money($review['shipping']),
            'tax' => $money($review['tax']),
            'total' => $money($review['total']),
        ];
    }

    private function getCatalogProducts(): array
    {
        $currency = $this->stockflow->getStoreSettings()['currency'];
        $rows = $this->database->table('products', 'stockflow')
            ->select([
                'products.id',
                'products.category_id',
                'products.name',
                'products.slug',
                'products.description',
                'products.brand',
                'products.featured',
                'categories.name AS category_name',
                'product_variants.id AS variant_id',
                'product_variants.sku',
                'product_variants.option_label',
                'product_variants.price',
                'product_variants.compare_at_price',
                'product_variants.weight_grams',
                'inventory.quantity_on_hand',
                'inventory.quantity_reserved',
            ])
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->join('product_variants', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('inventory', 'inventory.variant_id', '=', 'product_variants.id')
            ->where('products.status', '=', 'active')
            ->where('product_variants.status', '=', 'active')
            ->orderBy('products.id', 'DESC')
            ->get();

        $products = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($products[$id])) {
                $image = $this->database->table('product_images', 'stockflow')
                    ->select(['image_url', 'alt_text'])
                    ->where('product_id', '=', $id)
                    ->orderBy('sort_order')
                    ->first();
                $products[$id] = [
                    'id' => $id,
                    'category_id' => $row['category_id'],
                    'category_name' => $row['category_name'] ?? '',
                    'name' => (string) $row['name'],
                    'slug' => (string) $row['slug'],
                    'description' => (string) ($row['description'] ?? ''),
                    'brand' => (string) ($row['brand'] ?? ''),
                    'featured' => (bool) $row['featured'],
                    'image_url' => $image['image_url'] ?? '',
                    'image_alt' => $image['alt_text'] ?? $row['name'],
                    'variants' => [],
                    'available' => false,
                ];
            }
            $availableQuantity = max(
                0,
                (int) ($row['quantity_on_hand'] ?? 0) - (int) ($row['quantity_reserved'] ?? 0)
            );
            $variant = [
                'id' => (int) $row['variant_id'],
                'sku' => (string) $row['sku'],
                'option_label' => (string) ($row['option_label'] ?? ''),
                'price' => (float) $row['price'],
                'compare_at_price' => $row['compare_at_price'],
                'weight_grams' => (int) ($row['weight_grams'] ?? 0),
                'available_quantity' => $availableQuantity,
                'display_price' => (new Price((float) $row['price'], $currency))->format(),
            ];
            $products[$id]['variants'][] = $variant;
            if ($availableQuantity > 0) {
                $products[$id]['available'] = true;
            }
        }

        foreach ($products as &$product) {
            usort($product['variants'], static fn (array $a, array $b): int => $a['price'] <=> $b['price']);
            $availableVariants = array_values(array_filter(
                $product['variants'],
                static fn (array $variant): bool => $variant['available_quantity'] > 0
            ));
            $product['price'] = ($availableVariants[0] ?? $product['variants'][0] ?? ['price' => 0.0])['price'];
            $product['display_price'] = (new Price(
                $product['price'],
                $currency
            ))->format();
        }
        unset($product);
        return array_values($products);
    }

    private function getCartLines(): array
    {
        $stored = SessionStorage::get(self::CART_KEY);
        if (!is_array($stored)) {
            return [];
        }
        $currency = $this->stockflow->getStoreSettings()['currency'];
        $products = [];
        foreach ($this->getCatalogProducts() as $product) {
            foreach ($product['variants'] as $variant) {
                $products[(int) $variant['id']] = [$product, $variant];
            }
        }

        $cart = [];
        $validStored = [];
        foreach ($stored as $variantId => $quantity) {
            $variantId = filter_var($variantId, FILTER_VALIDATE_INT);
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
            if ($variantId === false || $quantity === false || $quantity < 1 || !isset($products[$variantId])) {
                continue;
            }
            [$product, $variant] = $products[$variantId];
            $validStored[$variantId] = $quantity;
            $cart[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'line_total' => (new Price(
                    $variant['price'],
                    $currency
                ))->multiply($quantity)->format(),
                'stock_ok' => $quantity <= $variant['available_quantity'],
            ];
        }
        if ($validStored !== $stored) {
            SessionStorage::add(self::CART_KEY, $validStored);
        }
        return $cart;
    }

    private function updateCart(Request $request): void
    {
        $action = $request->request->get('_action');
        $cart = SessionStorage::get(self::CART_KEY);
        if (!is_array($cart)) {
            $cart = [];
        }

        if ($action === 'clear') {
            SessionStorage::remove(self::CART_KEY);
            $this->clearCheckoutState();
            return;
        }

        $variantId = filter_var($request->request->get('variant_id'), FILTER_VALIDATE_INT);
        if ($variantId === false || $variantId < 1) {
            throw new InvalidArgumentException('Select a valid product option.');
        }
        if ($action === 'remove') {
            unset($cart[$variantId]);
            SessionStorage::add(self::CART_KEY, $cart);
            $this->clearCheckoutState();
            return;
        }

        $quantity = filter_var($request->request->get('quantity'), FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('Choose a quantity between 1 and 99.');
        }
        $variant = $this->availableVariant($variantId);
        if ($variant === null) {
            throw new InvalidArgumentException('The requested quantity is not available.');
        }

        if ($action === 'add') {
            $quantity += (int) ($cart[$variantId] ?? 0);
            if ($quantity > 99 || $quantity > $variant['available_quantity']) {
                throw new InvalidArgumentException('The requested quantity is not available.');
            }
        } elseif ($action !== 'update') {
            throw new InvalidArgumentException('This cart action is not recognized.');
        } elseif ($quantity > $variant['available_quantity']) {
            throw new InvalidArgumentException('The requested quantity is not available.');
        }
        $cart[$variantId] = $quantity;
        SessionStorage::add(self::CART_KEY, $cart);
        $this->clearCheckoutState();
    }

    private function availableVariant(int $variantId): ?array
    {
        $variant = $this->database->table('product_variants', 'stockflow')
            ->select([
                'product_variants.id',
                'product_variants.status AS variant_status',
                'products.status AS product_status',
                'inventory.quantity_on_hand',
                'inventory.quantity_reserved',
            ])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('inventory', 'inventory.variant_id', '=', 'product_variants.id')
            ->where('product_variants.id', '=', $variantId)
            ->first();
        if (
            $variant === null
            || $variant['variant_status'] !== 'active'
            || $variant['product_status'] !== 'active'
        ) {
            return null;
        }
        $variant['available_quantity'] = max(
            0,
            (int) ($variant['quantity_on_hand'] ?? 0) - (int) ($variant['quantity_reserved'] ?? 0)
        );
        return $variant;
    }

    private function cartHasStock(array $cart): bool
    {
        foreach ($cart as $line) {
            if (!$line['stock_ok']) {
                return false;
            }
        }
        return $cart !== [];
    }

    private function formatCartSubtotal(array $cart, string $currency): string
    {
        $subtotal = new Price(0.0, $currency);
        foreach ($cart as $line) {
            $subtotal->add((new Price((float) $line['variant']['price'], $currency))->multiply((int) $line['quantity']));
        }
        return $subtotal->format();
    }

    private function removePurchasedCartItems(array $orderData): void
    {
        $cart = SessionStorage::get(self::CART_KEY);
        if (!is_array($cart)) {
            return;
        }

        foreach ($orderData['items'] ?? [] as $item) {
            $variantId = (int) ($item['variant_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($variantId < 1 || $quantity < 1 || !isset($cart[$variantId])) {
                continue;
            }
            $remaining = (int) $cart[$variantId] - $quantity;
            if ($remaining > 0) {
                $cart[$variantId] = $remaining;
            } else {
                unset($cart[$variantId]);
            }
        }
        if ($cart === []) {
            SessionStorage::remove(self::CART_KEY);
        } else {
            SessionStorage::add(self::CART_KEY, $cart);
        }
    }

    private function clearCheckoutState(): void
    {
        SessionStorage::remove(self::CHECKOUT_DATA_KEY);
        SessionStorage::remove(self::CHECKOUT_REVIEW_KEY);
    }

    private function formString(array $form, string $key, string $label): string
    {
        $value = $form[$key] ?? '';
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Select a valid ' . strtolower($label) . '.');
        }
        return trim($value);
    }

    private function renderPage(string $template, array $data = [], int $status = 200): Response
    {
        $cart = SessionStorage::get(self::CART_KEY);
        $cartCount = is_array($cart) ? array_sum($cart) : 0;
        $data += [
            'store_name' => $this->stockflow->getStoreSettings()['store_name'],
            'cart_count' => $cartCount,
        ];
        return $this->renderTwig($template, $data, $status);
    }

    private function csrfToken(Request $request): string
    {
        $secret = $_ENV['CSRF_TOKEN_SECRET'] ?? getenv('CSRF_TOKEN_SECRET') ?: '';
        return $secret !== '' ? Url::generateToken($request, $secret) : '';
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query->get($key, '');
        return is_string($value) ? $value : '';
    }

    private function accountDestination(Request $request): string
    {
        $destination = $request->query->get('destination', '/account');
        if (!is_string($destination)) {
            return '/account';
        }
        $decodedDestination = rawurldecode($destination);
        if (
            !str_starts_with($destination, '/')
            || str_starts_with($decodedDestination, '//')
            || str_contains($decodedDestination, '\\')
            || parse_url($destination, PHP_URL_HOST) !== null
        ) {
            return '/account';
        }
        return $destination;
    }

    private function authenticatedUser(): ?User
    {
        $currentUser = getAppContainer()->get('current_user');
        if (!$currentUser instanceof CurrentUser || !$currentUser->isLoggedIn()) {
            return null;
        }
        return $currentUser->getUser();
    }

    private function log(string $message, array $order, \Throwable $exception): void
    {
        getAppContainer()->get('logger')->error($message, [
            'order_number' => $order['order_number'] ?? null,
            'exception' => $exception->getMessage(),
        ]);
    }
}
