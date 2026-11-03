<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Controller;

use InvalidArgumentException;
use JsonException;
use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\stockflow\src\Payment\PaymentMethodRegistry;
use Simp\Pindrop\Modules\stockflow\src\Services\AdjustmentConfigurationService;
use Simp\Pindrop\Modules\stockflow\src\Services\CheckoutIdempotencyService;
use Simp\Pindrop\Modules\stockflow\src\Services\ManualOrderService;
use Simp\Pindrop\Modules\stockflow\src\Services\ShippingMethodService;
use Simp\Pindrop\Modules\stockflow\src\Services\StockflowApiTokenService;
use Simp\Pindrop\Modules\stockflow\src\Services\StockflowService;
use Simp\Pindrop\Routing\Url;
use Simp\Price\lib\Price;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StockflowApiController extends ControllerBase
{
    public function __construct(
        private readonly StockflowApiTokenService $tokens,
        private readonly StockflowService $stockflow,
        private readonly ManualOrderService $orders,
        private readonly ShippingMethodService $shipping,
        private readonly AdjustmentConfigurationService $adjustments,
        private readonly PaymentMethodRegistry $paymentMethods,
        private readonly CheckoutIdempotencyService $checkoutIdempotency
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new self(
            $container->get('stockflow.api_tokens'),
            $container->get('stockflow.service'),
            $container->get('stockflow.manual_orders'),
            $container->get('stockflow.shipping_methods'),
            $container->get('stockflow.adjustments'),
            $container->get('stockflow.payment_methods'),
            $container->get('stockflow.checkout_idempotency')
        );
    }

    public function tokens(Request $request, string $route_name, array $options): Response
    {
        $user = getAppContainer()->get('current_user')?->getUser();
        $userId = $user?->getId();
        if ($userId === null || !$user->hasPermission('can_manage_stockflow')) {
            return new Response('Stockflow management permission is required.', 403);
        }

        $issuedToken = null;
        $error = null;
        if ($request->isMethod('POST')) {
            try {
                if ($request->request->get('_action') === 'issue_token') {
                    $issuedToken = $this->tokens->issue(
                        $userId,
                        $request->request->get('name', '')
                    );
                } elseif ($request->request->get('_action') === 'revoke_token') {
                    $tokenId = filter_var($request->request->get('token_id'), FILTER_VALIDATE_INT);
                    if ($tokenId === false || $tokenId < 1) {
                        throw new InvalidArgumentException('Select a valid API token.');
                    }
                    $this->tokens->revoke($userId, $tokenId);
                } else {
                    throw new InvalidArgumentException('Unknown API token action.');
                }
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->renderTwig('@stockflow/api-tokens.twig', [
            'page_title' => 'API tokens',
            'store_name' => $this->stockflow->getStoreSettings()['store_name'],
            'tokens' => $this->tokens->forUser($userId),
            'issued_token' => $issuedToken,
            'error' => $error,
            'csrf_token' => !empty($_ENV['CSRF_TOKEN_SECRET'])
                ? Url::generateToken($request, $_ENV['CSRF_TOKEN_SECRET'])
                : '',
        ]);
    }

    public function scan(Request $request, string $route_name, array $options): JsonResponse
    {
        if (!$this->authorize($request)) {
            return $this->error('A valid Stockflow API bearer token is required.', 401);
        }
        $data = $this->jsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $code = $data['code'] ?? null;
        if (!is_string($code) || trim($code) === '' || strlen($code) > 2048) {
            return $this->error('Provide a scanned product code of 1 to 2048 characters.', 400);
        }

        try {
            $variant = $this->resolveVariant(trim($code));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        if ($variant === null) {
            return $this->error('No product variant matches this code.', 404);
        }

        return new JsonResponse([
            'product' => $this->publicVariant($variant),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function checkoutOptions(Request $request, string $route_name, array $options): JsonResponse
    {
        if (!$this->authorize($request)) {
            return $this->error('A valid Stockflow API bearer token is required.', 401);
        }

        $currency = $this->stockflow->getStoreSettings()['currency'];
        $shippingMethods = [[
            'id' => 'pickup',
            'name' => 'In-store pickup',
            'type' => 'pickup',
        ]];
        foreach ($this->shipping->getMethods() as $method) {
            if (($method['status'] ?? '') === 'active') {
                $shippingMethods[] = [
                    'id' => (string) $method['id'],
                    'name' => (string) $method['name'],
                    'type' => (string) $method['type'],
                ];
            }
        }

        $paymentMethods = [];
        foreach ($this->paymentMethods->getMethods() as $method) {
            if (
                $method['enabled']
                && $method['configured']
                && $method['type'] === 'offline'
                && in_array($method['id'], ['cash', 'quote', 'purchase_order'], true)
                && $this->paymentMethods->supportsCurrency((string) $method['id'], $currency)
            ) {
                $paymentMethods[] = [
                    'id' => $method['id'],
                    'label' => $method['label'],
                ];
            }
        }

        return new JsonResponse([
            'currency' => $currency,
            'shipping_methods' => $shippingMethods,
            'payment_methods' => $paymentMethods,
            'taxes' => $this->publicRules($this->adjustments->getRules('taxes', true), $currency),
            'discounts' => $this->publicRules($this->adjustments->getRules('discounts', true), $currency),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function checkout(Request $request, string $route_name, array $options): JsonResponse
    {
        if (!$this->authorize($request)) {
            return $this->error('A valid Stockflow API bearer token is required.', 401);
        }
        $data = $this->jsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        try {
            $review = $this->calculateCheckoutReview($data);
            if ($review instanceof JsonResponse) {
                return $review;
            }
            return new JsonResponse([
                'review' => $review['payload'],
                'review_hash' => $review['hash'],
                'confirmation_required' => true,
                'confirm_endpoint' => '/api/stockflow/v1/checkout/confirm',
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            getAppContainer()->get('logger')->error('Stockflow checkout review failed', [
                'exception' => $exception->getMessage(),
            ]);
            return $this->error('The order review could not be calculated.', 500);
        }
    }

    public function checkoutConfirm(Request $request, string $route_name, array $options): JsonResponse
    {
        $user = $this->tokens->authenticate($request->headers->get('Authorization'));
        if ($user === null) {
            return $this->error('A valid Stockflow API bearer token is required.', 401);
        }
        $data = $this->jsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $reviewHash = $data['review_hash'] ?? null;
        if (!is_string($reviewHash) || preg_match('/^[a-f0-9]{64}$/D', $reviewHash) !== 1) {
            return $this->error('A valid review_hash from the checkout review is required.', 422);
        }
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if (
            !is_string($idempotencyKey)
            || preg_match('/^[\x21-\x7E]{1,128}$/D', $idempotencyKey) !== 1
        ) {
            return $this->error('Provide an Idempotency-Key header containing 1 to 128 visible ASCII characters.', 400);
        }

        try {
            $claim = $this->checkoutIdempotency->claim(
                (int) $user->getId(),
                $idempotencyKey,
                hash('sha256', json_encode($this->canonicalize($data), JSON_THROW_ON_ERROR))
            );
        } catch (\Throwable $exception) {
            getAppContainer()->get('logger')->error('Stockflow checkout idempotency could not be claimed', [
                'exception' => $exception->getMessage(),
            ]);
            return $this->error('Checkout could not be safely started. Retry with the same Idempotency-Key.', 500);
        }
        if ($claim['state'] === 'conflict') {
            return $this->error('This Idempotency-Key was already used with a different checkout request.', 409);
        }
        if ($claim['state'] === 'replay') {
            return new JsonResponse($claim['body'], $claim['status_code'], ['Cache-Control' => 'no-store']);
        }
        $idempotencyKeyHash = $claim['key_hash'] ?? hash('sha256', $idempotencyKey);
        if ($claim['state'] === 'processing') {
            if (isset($claim['order_id'])) {
                try {
                    $savedOrder = $this->recoverCheckoutOrder((int) $claim['order_id'], (string) ($data['payment_method'] ?? ''));
                    if ($savedOrder !== null) {
                        $body = ['order' => $this->savedOrderPayload($savedOrder, (string) $data['payment_method'])];
                        $this->checkoutIdempotency->completeRecovered(
                            (int) $user->getId(),
                            $idempotencyKeyHash,
                            (int) $claim['order_id'],
                            201,
                            $body
                        );
                        return new JsonResponse($body, 201, ['Cache-Control' => 'no-store']);
                    }
                } catch (\Throwable $exception) {
                    getAppContainer()->get('logger')->error('Stockflow checkout retry recovery failed', [
                        'order_id' => $claim['order_id'],
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
            return new JsonResponse([
                'error' => 'This checkout request is already being processed. Retry later using the same Idempotency-Key.',
                'order_created' => isset($claim['order_id']),
            ], 409, ['Cache-Control' => 'no-store']);
        }

        $createdOrderNumber = null;
        $claimToken = $claim['claim_token'];
        try {
            $review = $this->calculateCheckoutReview($data);
            if ($review instanceof JsonResponse) {
                $this->checkoutIdempotency->release((int) $user->getId(), $idempotencyKeyHash, $claimToken);
                return $review;
            }
            if (!hash_equals($review['hash'], $reviewHash)) {
                $body = [
                    'error' => 'The order changed since it was reviewed. Review the updated order before confirming.',
                    'review' => $review['payload'],
                    'review_hash' => $review['hash'],
                    'confirmation_required' => true,
                ];
                $this->checkoutIdempotency->complete(
                    (int) $user->getId(),
                    $idempotencyKeyHash,
                    $claimToken,
                    409,
                    $body
                );
                return new JsonResponse($body, 409, ['Cache-Control' => 'no-store']);
            }
            $checkout = $review['checkout'];
            $order = $this->orders->createCheckoutOrder(
                $checkout['order_data'],
                $this->adjustments->getRules('taxes', true),
                $this->adjustments->getRules('discounts', true),
                function (int $orderId) use ($user, $idempotencyKeyHash, $claimToken): void {
                    $this->checkoutIdempotency->attachOrder(
                        (int) $user->getId(),
                        $idempotencyKeyHash,
                        $claimToken,
                        $orderId
                    );
                }
            );
            $createdOrderNumber = (string) $order['order_number'];
            if ($checkout['payment_method'] === 'cash') {
                $createdOrder = $this->orders->getOrder((int) $order['id']);
                $payment = $createdOrder['payments'][0] ?? null;
                if ($payment === null) {
                    throw new \RuntimeException('The cash payment record was not created.');
                }
                $this->orders->receiveManualPayment((int) $payment['id']);
            }
            $savedOrder = $this->orders->getOrder((int) $order['id']);
            if ($savedOrder === null) {
                throw new \RuntimeException('The created order could not be reloaded.');
            }
            $body = [
                'order' => $this->savedOrderPayload($savedOrder, $checkout['payment_method']),
            ];
            $this->checkoutIdempotency->complete(
                (int) $user->getId(),
                $idempotencyKeyHash,
                $claimToken,
                201,
                $body
            );
            return new JsonResponse($body, 201, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException $exception) {
            if ($createdOrderNumber !== null) {
                getAppContainer()->get('logger')->error('Stockflow app checkout was created but finalization failed', [
                    'order_number' => $createdOrderNumber,
                    'exception' => $exception->getMessage(),
                ]);
                $body = [
                    'error' => 'The order was created but checkout finalization failed. Check this order before retrying.',
                    'order_created' => true,
                    'order_number' => $createdOrderNumber,
                ];
                $this->checkoutIdempotency->complete(
                    (int) $user->getId(),
                    $idempotencyKeyHash,
                    $claimToken,
                    500,
                    $body
                );
                return new JsonResponse($body, 500, ['Cache-Control' => 'no-store']);
            }
            $this->checkoutIdempotency->release((int) $user->getId(), $idempotencyKeyHash, $claimToken);
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            getAppContainer()->get('logger')->error('Stockflow app checkout failed', [
                'order_number' => $createdOrderNumber,
                'exception' => $exception->getMessage(),
            ]);
            $body = [
                'error' => $createdOrderNumber === null
                    ? 'Checkout could not be completed.'
                    : 'The order was created but checkout finalization failed. Check this order before retrying.',
                'order_created' => $createdOrderNumber !== null,
                'order_number' => $createdOrderNumber,
            ];
            if ($createdOrderNumber === null) {
                $this->checkoutIdempotency->release((int) $user->getId(), $idempotencyKeyHash, $claimToken);
            } else {
                $this->checkoutIdempotency->complete(
                    (int) $user->getId(),
                    $idempotencyKeyHash,
                    $claimToken,
                    500,
                    $body
                );
            }
            return new JsonResponse($body, 500, ['Cache-Control' => 'no-store']);
        }
    }

    private function recoverCheckoutOrder(int $orderId, string $paymentMethod): ?array
    {
        $order = $this->orders->getOrder($orderId);
        if ($order === null) {
            return null;
        }
        if ($paymentMethod === 'cash' && ($order['payments'][0]['status'] ?? '') === 'pending') {
            $this->orders->receiveManualPayment((int) $order['payments'][0]['id']);
            $order = $this->orders->getOrder($orderId);
        }
        if ($order === null) {
            return null;
        }
        if ($paymentMethod === 'cash' && ($order['payments'][0]['status'] ?? '') !== 'paid') {
            return null;
        }
        return $order;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            $item = $this->canonicalize($item);
        }
        unset($item);
        return $value;
    }

    private function checkoutData(array $data): array|JsonResponse
    {
        $customer = $data['customer'] ?? null;
        $items = $data['items'] ?? null;
        if (!is_array($customer) || !is_array($items) || $items === []) {
            return $this->error('Customer details and at least one order item are required.', 422);
        }
        $currency = $this->stockflow->getStoreSettings()['currency'];
        $paymentMethodId = $this->requiredString($data['payment_method'] ?? null, 'Payment method');
        $paymentMethod = $this->paymentMethods->getMethod($paymentMethodId);
        if (
            $paymentMethod === null
            || !$paymentMethod['enabled']
            || !$paymentMethod['configured']
            || $paymentMethod['type'] !== 'offline'
            || !in_array($paymentMethodId, ['cash', 'quote', 'purchase_order'], true)
            || !$this->paymentMethods->supportsCurrency($paymentMethodId, $currency)
        ) {
            return $this->error('Select an enabled Cash, Quote, or Purchase Order payment method.', 422);
        }

        $billingAddress = $data['billing_address'] ?? null;
        $shippingAddress = $data['shipping_address'] ?? $billingAddress;
        if ($billingAddress !== null && !is_array($billingAddress)) {
            return $this->error('Billing address must be an object.', 422);
        }
        if ($shippingAddress !== null && !is_array($shippingAddress)) {
            return $this->error('Shipping address must be an object.', 422);
        }
        $shippingMethod = $data['shipping_method'] ?? 'pickup';
        if (!is_string($shippingMethod) || $shippingMethod === '') {
            return $this->error('Select a valid shipping method.', 422);
        }
        $sameShipping = $shippingAddress === $billingAddress;
        if ($shippingMethod !== 'pickup' && $shippingAddress === null) {
            return $this->error('Provide an address for delivery.', 422);
        }

        $orderData = [
            'email' => $customer['email'] ?? '',
            'customer_first_name' => $customer['first_name'] ?? '',
            'customer_last_name' => $customer['last_name'] ?? '',
            'customer_phone' => $customer['phone'] ?? '',
            'payment_method' => $paymentMethodId,
            'payment_data' => [],
            'items' => $items,
            'shipping_method' => $shippingMethod,
            'order_tax_ids' => $data['order_tax_ids'] ?? [],
            'order_discount_id' => $data['order_discount_id'] ?? '',
            'same_shipping' => $sameShipping ? 'yes' : 'no',
        ];
        $orderData += $this->addressFields($billingAddress, 'billing');
        if (!$sameShipping) {
            $orderData += $this->addressFields($shippingAddress, 'shipping');
        }
        return [
            'order_data' => $orderData,
            'payment_method' => $paymentMethodId,
        ];
    }

    private function calculateCheckoutReview(array $data): array|JsonResponse
    {
        $checkout = $this->checkoutData($data);
        if ($checkout instanceof JsonResponse) {
            return $checkout;
        }
        $review = $this->orders->previewOrder(
            $checkout['order_data'],
            $this->adjustments->getRules('taxes', true),
            $this->adjustments->getRules('discounts', true)
        );
        $payload = $this->reviewPayload($review);
        return [
            'checkout' => $checkout,
            'payload' => $payload,
            'hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }

    private function reviewPayload(array $review): array
    {
        $currency = (string) $review['currency'];
        return [
            'currency' => $currency,
            'customer' => $review['customer'],
            'billing_address' => $review['billing_address'],
            'shipping_address' => $review['shipping_address'],
            'payment_method' => $review['payment_method'],
            'shipping_method' => $this->shippingPayload($review['shipping_method']),
            'items' => array_map(fn (array $item): array => [
                'variant_id' => (int) $item['variant_id'],
                'product_id' => (int) $item['product_id'],
                'sku' => (string) $item['sku'],
                'name' => (string) $item['name'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => $this->pricePayload($item['unit_price'], $currency),
                'subtotal' => $this->pricePayload($item['subtotal'], $currency),
                'discount' => $this->pricePayload($item['discount'], $currency),
                'tax' => $this->pricePayload($item['tax'], $currency),
                'total' => $this->pricePayload($item['total'], $currency),
                'discounts' => $this->adjustmentPayload($item['discounts'], $currency),
                'taxes' => $this->adjustmentPayload($item['taxes'], $currency),
            ], $review['items']),
            'subtotal' => $this->pricePayload($review['subtotal'], $currency),
            'discount' => $this->pricePayload($review['discount'], $currency),
            'shipping' => $this->pricePayload($review['shipping'], $currency),
            'tax' => $this->pricePayload($review['tax'], $currency),
            'total' => $this->pricePayload($review['total'], $currency),
            'discounts' => $this->adjustmentPayload($review['discounts'], $currency),
            'taxes' => $this->adjustmentPayload($review['taxes'], $currency),
        ];
    }

    private function savedOrderPayload(array $order, string $paymentMethod): array
    {
        $currency = (string) $order['currency_code'];
        $payments = $order['payments'] ?? [];
        $adjustments = $order['adjustments'] ?? [];
        $items = array_map(function (array $item) use ($currency): array {
            $itemAdjustments = $item['item_adjustments'] ?? [];
            if (is_string($itemAdjustments)) {
                $decoded = json_decode($itemAdjustments, true);
                $itemAdjustments = is_array($decoded) ? $decoded : [];
            }
            $subtotal = (new Price((float) $item['unit_price'], $currency))->multiply((int) $item['quantity']);
            $discount = new Price((float) $item['discount_total'], $currency);
            $tax = new Price(0.0, $currency);
            foreach ($itemAdjustments as $adjustment) {
                if (($adjustment['kind'] ?? '') === 'tax' && isset($adjustment['applied_amount'])) {
                    $tax->add(new Price((float) $adjustment['applied_amount'], $currency));
                }
            }
            return [
                'variant_id' => (int) $item['variant_id'],
                'sku' => (string) $item['sku'],
                'name' => (string) $item['product_name'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => $this->pricePayload(new Price((float) $item['unit_price'], $currency), $currency),
                'subtotal' => $this->pricePayload($subtotal, $currency),
                'discount' => $this->pricePayload($discount, $currency),
                'tax' => $this->pricePayload($tax, $currency),
                'total' => $this->pricePayload(new Price((float) $item['line_total'], $currency), $currency),
                'discounts' => array_values(array_filter(
                    $itemAdjustments,
                    static fn (array $adjustment): bool => ($adjustment['kind'] ?? '') === 'discount'
                )),
                'taxes' => array_values(array_filter(
                    $itemAdjustments,
                    static fn (array $adjustment): bool => ($adjustment['kind'] ?? '') === 'tax'
                )),
            ];
        }, $order['items'] ?? []);
        $orderTaxes = array_values(array_filter(
            $adjustments,
            static fn (array $adjustment): bool => ($adjustment['kind'] ?? '') === 'tax'
        ));
        $orderDiscounts = array_values(array_filter(
            $adjustments,
            static fn (array $adjustment): bool => ($adjustment['kind'] ?? '') === 'discount'
        ));
        $allTaxes = array_merge($orderTaxes, ...array_map(static fn (array $item): array => $item['taxes'], $items));
        $allDiscounts = array_merge($orderDiscounts, ...array_map(static fn (array $item): array => $item['discounts'], $items));
        $shippingMethodId = (string) ($order['shipping_method_id'] ?? 'pickup');
        return [
            'id' => (int) $order['id'],
            'order_number' => (string) $order['order_number'],
            'status' => (string) $order['status'],
            'customer' => [
                'email' => (string) $order['email'],
            ],
            'billing_address' => $order['billing_address'],
            'shipping_address' => $order['shipping_address'],
            'payment' => [
                'method' => $paymentMethod,
                'status' => (string) ($payments[0]['status'] ?? 'pending'),
            ],
            'shipping_method' => $this->shippingPayload($shippingMethodId),
            'items' => $items,
            'subtotal' => $this->moneyValue($order['subtotal'], $currency),
            'discount' => $this->moneyValue($order['discount_total'], $currency),
            'shipping' => $this->moneyValue($order['shipping_total'], $currency),
            'tax' => $this->moneyValue($order['tax_total'], $currency),
            'total' => $this->moneyValue($order['grand_total'], $currency),
            'discounts' => $this->adjustmentPayload($allDiscounts, $currency),
            'taxes' => $this->adjustmentPayload($allTaxes, $currency),
        ];
    }

    private function adjustmentPayload(array $adjustments, string $currency): array
    {
        return array_map(fn (array $adjustment): array => [
            'id' => (string) ($adjustment['id'] ?? ''),
            'name' => (string) ($adjustment['name'] ?? ''),
            'description' => (string) ($adjustment['description'] ?? ''),
            'kind' => (string) ($adjustment['kind'] ?? ''),
            'calculation_type' => (string) ($adjustment['type'] ?? ''),
            'apply_to' => (string) ($adjustment['apply_to'] ?? ''),
            'product_id' => isset($adjustment['product_id']) ? (int) $adjustment['product_id'] : null,
            'applied_amount' => $this->moneyValue($adjustment['applied_amount'] ?? '0.00', $currency),
        ], $adjustments);
    }

    private function shippingPayload(string $methodId): array
    {
        $method = $this->shipping->getMethod($methodId);
        return [
            'id' => $methodId,
            'name' => (string) ($method['name'] ?? $methodId),
            'type' => (string) ($method['type'] ?? ''),
        ];
    }

    private function pricePayload(Price $price, string $currency): array
    {
        return $this->moneyValue($price->getAmount(), $currency);
    }

    private function authorize(Request $request): bool
    {
        return $this->tokens->authenticate($request->headers->get('Authorization')) !== null;
    }

    private function jsonBody(Request $request): array|JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error('Request body must contain valid JSON.', 400);
        }
        if (!is_array($data)) {
            return $this->error('Request body must be a JSON object.', 400);
        }
        return $data;
    }

    private function resolveVariant(string $code): ?array
    {
        $payload = json_decode($code, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
            if (
                ($payload['schema'] ?? null) !== 'stockflow.variant'
                || ($payload['version'] ?? null) !== 1
                || filter_var($payload['variant_id'] ?? null, FILTER_VALIDATE_INT) === false
                || !is_string($payload['sku'] ?? null)
            ) {
                throw new InvalidArgumentException('The scanned Stockflow QR code has an unsupported format.');
            }
            $variant = $this->stockflow->getVariantForApi((int) $payload['variant_id']);
            return $this->matchesPayload($variant, $payload) ? $variant : null;
        }

        if (preg_match('/^SF1:([1-9][0-9]*):(.+)$/D', $code, $matches) === 1) {
            $variant = $this->stockflow->getVariantForApi((int) $matches[1]);
            return $variant !== null && hash_equals((string) $variant['sku'], $matches[2])
                ? $variant
                : null;
        }

        $variants = $this->stockflow->getVariantsForApiCode($code);
        if (count($variants) > 1) {
            throw new InvalidArgumentException('This barcode matches multiple variants. Use the printed Stockflow QR code.');
        }
        return $variants[0] ?? null;
    }

    private function matchesPayload(?array $variant, array $payload): bool
    {
        if (
            $variant === null
            || (int) $variant['product_id'] !== (int) ($payload['product_id'] ?? 0)
            || !hash_equals((string) $variant['sku'], (string) $payload['sku'])
        ) {
            return false;
        }
        if (array_key_exists('barcode', $payload)) {
            $barcode = $payload['barcode'];
            $storedBarcode = ($variant['barcode'] ?? '') === '' ? null : (string) $variant['barcode'];
            if ($barcode !== $storedBarcode) {
                return false;
            }
        }
        return true;
    }

    private function publicVariant(array $variant): array
    {
        $onHand = (int) ($variant['quantity_on_hand'] ?? 0);
        $reserved = (int) ($variant['quantity_reserved'] ?? 0);
        $available = max(0, $onHand - $reserved);
        $currency = $this->stockflow->getStoreSettings()['currency'];
        $active = $variant['variant_status'] === 'active' && $variant['product_status'] === 'active';
        return [
            'id' => (int) $variant['id'],
            'product_id' => (int) $variant['product_id'],
            'sku' => (string) $variant['sku'],
            'barcode' => $variant['barcode'],
            'name' => (string) $variant['product_name'],
            'slug' => (string) $variant['product_slug'],
            'brand' => $variant['brand'],
            'description' => $variant['description'],
            'category' => $variant['category_name'],
            'option_label' => $variant['option_label'],
            'status' => $active ? 'active' : 'unavailable',
            'stock' => [
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $available,
                'in_stock' => $active && $available > 0,
            ],
            'price' => [
                'amount' => (new Price((float) $variant['price'], $currency))->format(false),
                'currency' => $currency,
            ],
            'compare_at_price' => $variant['compare_at_price'] === null ? null : [
                'amount' => (new Price((float) $variant['compare_at_price'], $currency))->format(false),
                'currency' => $currency,
            ],
            'weight_grams' => $variant['weight_grams'] === null ? null : (int) $variant['weight_grams'],
        ];
    }

    private function publicRules(array $rules, string $currency): array
    {
        return array_map(static function (array $rule) use ($currency): array {
            $amount = $rule['amount'] ?? null;
            if ($amount !== null && ($rule['calculation_type'] ?? '') === 'fixed') {
                $amount = (new Price((float) $amount, $currency))->format(false);
            }
            return [
                'id' => (string) $rule['id'],
                'name' => (string) $rule['name'],
                'description' => (string) $rule['description'],
                'calculation_type' => (string) $rule['calculation_type'],
                'amount' => $amount === null ? null : (string) $amount,
                'currency' => ($rule['calculation_type'] ?? '') === 'fixed' ? $currency : null,
                'apply_to' => (string) ($rule['apply_to'] ?? 'legacy'),
                'product_id' => isset($rule['product_id']) ? (int) $rule['product_id'] : null,
            ];
        }, $rules);
    }

    private function addressFields(?array $address, string $prefix): array
    {
        if ($address === null) {
            return [];
        }
        $fields = [];
        foreach ([
            'first_name', 'last_name', 'company', 'line1', 'line2', 'city',
            'state', 'postal_code', 'country_code', 'phone',
        ] as $field) {
            $fields[$prefix . '_' . $field] = $address[$field] ?? '';
        }
        return $fields;
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return trim($value);
    }

    private function moneyValue(mixed $value, string $currency): array
    {
        return [
            'amount' => (new Price((float) $value, $currency))->format(false),
            'currency' => $currency,
        ];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status, ['Cache-Control' => 'no-store']);
    }
}
