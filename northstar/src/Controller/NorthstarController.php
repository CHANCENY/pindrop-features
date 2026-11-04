<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\northstar\src\Controller;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\stockflow\src\Payment\PaymentMethodRegistry;
use Simp\Pindrop\Modules\stockflow\src\Services\AdjustmentConfigurationService;
use Simp\Pindrop\Modules\stockflow\src\Services\ManualOrderService;
use Simp\Pindrop\Modules\stockflow\src\Services\ShippingMethodService;
use Simp\Pindrop\Modules\stockflow\src\Services\StockflowService;
use Simp\Pindrop\Routing\Url;
use Simp\Pindrop\Session\SessionStorage;
use Simp\Price\lib\Price;
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
        $slug = $options['slug'] ?? '';
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

        return $this->renderPage('@northstar/product.twig', [
            'product' => $product,
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

    public function checkout(Request $request, string $route_name, array $options): Response
    {
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
            if ($action === 'review') {
                try {
                    if (!$this->cartHasStock($cart)) {
                        throw new InvalidArgumentException('Some cart quantities are no longer available. Update your cart and try again.');
                    }
                    $orderData = $this->checkoutOrderData($request);
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
                    return $this->renderCheckoutForm(
                        $request,
                        $cart,
                        $shippingMethods,
                        $paymentMethods,
                        $exception->getMessage(),
                        $request->request->all()
                    );
                } catch (\Throwable $exception) {
                    $this->log('Northstar could not prepare the checkout review', [], $exception);
                    return $this->renderCheckoutForm(
                        $request,
                        $cart,
                        $shippingMethods,
                        $paymentMethods,
                        'Checkout is temporarily unavailable. Please try again.',
                        $request->request->all()
                    );
                }
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
            return $this->renderCheckoutForm(
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
        $user = getAppContainer()->get('current_user')?->getUser();
        if ($user === null) {
            return new RedirectResponse('/user/login?destination=%2Faccount');
        }

        $orders = [];
        if (
            $user->hasPermission('db.stockflow.storefront')
            || in_array($user->getRole(), ['admin', 'super_admin'], true)
        ) {
            $orders = $this->database->table('orders', 'stockflow')
                ->select([
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
        }
        foreach ($orders as &$order) {
            $order['display_total'] = (new Price(
                (float) $order['grand_total'],
                (string) $order['currency_code']
            ))->format();
        }
        unset($order);

        return $this->renderPage('@northstar/account.twig', [
            'orders' => $orders,
            'user_name' => $user->getFullName() ?? $user->getDisplayName() ?? '',
        ]);
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
            return $this->renderCheckoutForm(
                $request,
                $cart,
                $shippingMethods,
                $paymentMethods,
                'Your checkout review expired. Please review your order again.',
                is_array($storedData) ? $storedData : []
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
            $order = $this->orders->createCheckoutOrder(
                $storedData,
                $this->adjustments->getRules('taxes', true),
                $this->adjustments->getRules('discounts', true)
            );
            $this->linkOrderToSignedInCustomer($order + ['email' => $storedData['email']]);
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
            return $this->renderCheckoutForm(
                $request,
                $cart,
                $shippingMethods,
                $paymentMethods,
                $exception->getMessage(),
                $storedData
            );
        } catch (\Throwable $exception) {
            $this->log('Northstar checkout failed', [], $exception);
            return $this->renderCheckoutForm(
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

        return $this->renderPage('@northstar/checkout.twig', [
            'cart' => $cart,
            'shipping_methods' => $shippingMethods,
            'payment_methods' => $paymentMethods,
            'values' => $values,
            'error' => $error,
            'csrf_token' => $this->csrfToken($request),
            'authorize_client' => $authorizeClient,
            'currency' => $currency,
            'subtotal' => $this->formatCartSubtotal($cart, $currency),
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

    private function checkoutOrderData(Request $request): array
    {
        $form = $request->request->all();
        $paymentMethodId = $this->formString($form, 'payment_method', 'Payment method');
        $paymentMethod = null;
        foreach ($this->paymentMethods->getMethods() as $method) {
            if ($method['id'] === $paymentMethodId) {
                $paymentMethod = $method;
                break;
            }
        }
        if ($paymentMethod === null) {
            throw new InvalidArgumentException('Select a valid payment method.');
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
        $cart = $this->getCartLines();
        if ($cart === []) {
            throw new InvalidArgumentException('Your cart is empty.');
        }

        $shippingMethod = $this->formString($form, 'shipping_method', 'Shipping method');
        $orderData = [
            'email' => $form['email'] ?? '',
            'customer_first_name' => $form['first_name'] ?? '',
            'customer_last_name' => $form['last_name'] ?? '',
            'customer_phone' => $form['phone'] ?? '',
            'payment_method' => $paymentMethodId,
            'payment_data' => $paymentData,
            'items' => array_map(static fn (array $line): array => [
                'variant_id' => (int) $line['variant']['id'],
                'quantity' => (int) $line['quantity'],
            ], $cart),
            'shipping_method' => $shippingMethod,
            'order_tax_ids' => [],
            'order_discount_id' => '',
            'same_shipping' => 'yes',
        ];
        $addressFields = [
            'company', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code', 'phone',
        ];
        $addressProvided = $shippingMethod !== 'pickup';
        foreach ($addressFields as $field) {
            $value = $form['address_' . $field] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('Address fields must be single values.');
            }
            $orderData['billing_' . $field] = trim($value);
            $addressProvided = $addressProvided || trim($value) !== '';
        }
        $orderData['billing_first_name'] = $addressProvided ? $orderData['customer_first_name'] : '';
        $orderData['billing_last_name'] = $addressProvided ? $orderData['customer_last_name'] : '';
        if ($orderData['billing_phone'] === '') {
            $orderData['billing_phone'] = $orderData['customer_phone'];
        }
        return $orderData;
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
            return;
        }

        $variantId = filter_var($request->request->get('variant_id'), FILTER_VALIDATE_INT);
        if ($variantId === false || $variantId < 1) {
            throw new InvalidArgumentException('Select a valid product option.');
        }
        if ($action === 'remove') {
            unset($cart[$variantId]);
            SessionStorage::add(self::CART_KEY, $cart);
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

    private function linkOrderToSignedInCustomer(array $order): void
    {
        $user = getAppContainer()->get('current_user')?->getUser();
        if (
            $user === null
            || !in_array($user->getRole(), ['customer', 'user'], true)
            || !is_string($user->getEmail())
            || strcasecmp($user->getEmail(), (string) ($order['email'] ?? '')) !== 0
        ) {
            return;
        }

        try {
            $savedOrder = $this->database->table('orders', 'stockflow')
                ->select(['customer_id'])
                ->where('id', '=', (int) $order['id'])
                ->first();
            if ($savedOrder === null || empty($savedOrder['customer_id'])) {
                return;
            }
            $this->database->table('customers', 'stockflow')
                ->where('id', '=', (int) $savedOrder['customer_id'])
                ->where('user_id', 'IS NULL')
                ->update(['user_id' => (int) $user->getId()]);
        } catch (\Throwable $exception) {
            $this->log('Northstar could not link the customer account to the order', $order, $exception);
        }
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

    private function log(string $message, array $order, \Throwable $exception): void
    {
        getAppContainer()->get('logger')->error($message, [
            'order_number' => $order['order_number'] ?? null,
            'exception' => $exception->getMessage(),
        ]);
    }
}
