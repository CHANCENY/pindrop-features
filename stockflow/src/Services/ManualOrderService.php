<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Services;

use InvalidArgumentException;
use RuntimeException;
use Simp\Adjustment\lib\Adjustment;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Settings\Settings;
use Simp\Price\lib\Currency;
use Simp\Price\lib\Price;

final class ManualOrderService
{
    public function __construct(
        private readonly DatabaseService $database,
        private readonly Settings $settings,
        private readonly ShippingMethodService $shippingMethods
    ) {
    }

    public function getVariants(): array
    {
        $variants = $this->database->table('product_variants', 'stockflow')
            ->select([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.option_label',
                'product_variants.price',
                'product_variants.weight_grams',
                'products.name AS product_name',
                'inventory.quantity_on_hand',
                'inventory.quantity_reserved',
            ])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('inventory', 'inventory.variant_id', '=', 'product_variants.id')
            ->where('product_variants.status', '=', 'active')
            ->where('products.status', '=', 'active')
            ->orderBy('products.name')
            ->get();
        foreach ($variants as &$variant) {
            $variant['display_price'] = (new Price((float) $variant['price'], $this->currency()))->format();
        }
        unset($variant);
        return $variants;
    }

    public function getOrder(int $orderId): ?array
    {
        $order = $this->database->table('orders', 'stockflow')
            ->where('id', '=', $orderId)
            ->first();
        if ($order === null) {
            return null;
        }

        $order['items'] = $this->database->table('order_items', 'stockflow')
            ->where('order_id', '=', $orderId)
            ->get();
        foreach ($order['items'] as &$item) {
            $item['display_unit_price'] = $this->formatMoney($item['unit_price'], (string) $order['currency_code']);
            $item['display_line_total'] = $this->formatMoney($item['line_total'], (string) $order['currency_code']);
        }
        unset($item);
        foreach (['subtotal', 'discount_total', 'shipping_total', 'tax_total', 'grand_total'] as $amountField) {
            $order['display_' . $amountField] = $this->formatMoney(
                $order[$amountField],
                (string) $order['currency_code']
            );
        }
        $order['payments'] = $this->getOrderPayments($orderId);
        $order['billing_address'] = $this->addressById($order['billing_address_id'] ?? null);
        $order['shipping_address'] = $this->addressById($order['shipping_address_id'] ?? null);
        $adjustments = json_decode((string) ($order['order_adjustments'] ?? '[]'), true);
        $order['adjustments'] = is_array($adjustments) ? $adjustments : [];
        $order['shipping_method_id'] = '';
        foreach ($order['adjustments'] as $adjustment) {
            if (($adjustment['kind'] ?? '') === 'shipping_method') {
                $order['shipping_method_id'] = (string) ($adjustment['id'] ?? '');
                break;
            }
        }
        return $order;
    }

    public function getOrderPayments(int $orderId): array
    {
        $payments = $this->database->table('payments', 'stockflow')
            ->where('order_id', '=', $orderId)
            ->orderBy('created_at', 'DESC')
            ->get();
        foreach ($payments as &$payment) {
            $payment['display_amount'] = $this->formatMoney(
                $payment['amount'],
                (string) $payment['currency_code']
            );
        }
        unset($payment);
        return $payments;
    }

    public function getPayment(int $paymentId): ?array
    {
        $payment = $this->database->table('payments', 'stockflow')
            ->where('id', '=', $paymentId)
            ->first();
        if ($payment !== null) {
            $payment['display_amount'] = $this->formatMoney(
                $payment['amount'],
                (string) $payment['currency_code']
            );
        }
        return $payment;
    }

    public function deletePendingOrder(int $orderId): void
    {
        $this->database->transaction(function () use ($orderId): void {
            $cancelled = $this->database->table('orders', 'stockflow')
                ->where('id', '=', $orderId)
                ->where('status', '=', 'pending')
                ->update(['status' => 'cancelled']);
            if ($cancelled !== 1) {
                throw new InvalidArgumentException('Only pending orders can be deleted.');
            }
            $order = $this->database->table('orders', 'stockflow')
                ->where('id', '=', $orderId)
                ->first();
            if ($order === null || $order['status'] !== 'cancelled') {
                throw new InvalidArgumentException('Only pending orders can be deleted.');
            }
            foreach ($this->getOrderPayments($orderId) as $payment) {
                if (!$this->isOfflinePayment($payment['provider'])
                    || !in_array($payment['status'], ['pending', 'failed'], true)) {
                    throw new InvalidArgumentException('Orders with gateway payment history or completed payments cannot be deleted.');
                }
            }
            $items = $this->database->table('order_items', 'stockflow')
                ->where('order_id', '=', $orderId)
                ->get();
            foreach ($items as $item) {
                $inventory = $this->database->table('inventory', 'stockflow')
                    ->where('variant_id', '=', (int) $item['variant_id'])
                    ->first();
                if ($inventory === null || (int) $inventory['quantity_reserved'] < (int) $item['quantity']) {
                    throw new RuntimeException('Reserved order inventory is inconsistent; the order was not deleted.');
                }
                $updated = $this->database->table('inventory', 'stockflow')
                    ->where('variant_id', '=', (int) $item['variant_id'])
                    ->where('quantity_reserved', '=', (int) $inventory['quantity_reserved'])
                    ->update([
                        'quantity_reserved' => (int) $inventory['quantity_reserved'] - (int) $item['quantity'],
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Inventory changed while deleting the order.');
                }
            }
            $this->database->table('orders', 'stockflow')
                ->where('id', '=', $orderId)
                ->where('status', '=', 'cancelled')
                ->delete();
        });
    }

    public function createManualPayment(int $orderId, string $reference): int
    {
        $reference = $this->scalarString($reference, 'Payment reference');
        if (strlen($reference) > 180) {
            throw new InvalidArgumentException('Payment reference must be 180 characters or fewer.');
        }
        return $this->database->transaction(function () use ($orderId, $reference): int {
            $order = $this->lockPendingOrder($orderId);
            foreach ($this->getOrderPayments($orderId) as $payment) {
                if (in_array($payment['status'], ['paid', 'authorized'], true)
                    || $payment['status'] === 'pending') {
                    throw new InvalidArgumentException('An unresolved or completed payment already exists for this order.');
                }
            }
            return $this->database->table('payments', 'stockflow')->insert([
                'order_id' => $orderId,
                'provider' => 'manual',
                'provider_payment_id' => $reference !== '' ? $reference : null,
                'status' => 'pending',
                'amount' => $order['grand_total'],
                'currency_code' => $order['currency_code'],
            ]);
        });
    }

    public function createGatewayPayment(int $orderId, string $provider): array
    {
        $provider = $this->requiredString($provider, 'Payment method');
        if ($this->isOfflinePayment($provider)) {
            throw new InvalidArgumentException('Offline payment methods cannot be submitted through online checkout.');
        }
        return $this->database->transaction(function () use ($orderId, $provider): array {
            $order = $this->lockPendingOrder($orderId);
            foreach ($this->getOrderPayments($orderId) as $payment) {
                if (in_array($payment['status'], ['paid', 'authorized'], true)
                    || $payment['status'] === 'pending') {
                    throw new InvalidArgumentException('An unresolved or completed payment already exists for this order.');
                }
            }
            $paymentId = $this->database->table('payments', 'stockflow')->insert([
                'order_id' => $orderId,
                'provider' => $provider,
                'status' => 'pending',
                'amount' => $order['grand_total'],
                'currency_code' => $order['currency_code'],
            ]);
            return [
                'id' => $paymentId,
                'amount' => new Price((float) $order['grand_total'], (string) $order['currency_code']),
                'currency' => (string) $order['currency_code'],
                'order_number' => (string) $order['order_number'],
            ];
        });
    }

    public function recordGatewayPaymentAttempt(int $paymentId, array $result): void
    {
        $status = $result['status'] ?? '';
        if (!in_array($status, ['pending', 'authorized', 'paid', 'failed'], true)) {
            throw new InvalidArgumentException('Payment gateway returned an unsupported status.');
        }
        $payment = $this->getPayment($paymentId);
        if ($payment === null || $payment['status'] !== 'pending' || $this->isOfflinePayment($payment['provider'])) {
            throw new InvalidArgumentException('Gateway payment attempt is no longer pending.');
        }
        $providerPaymentId = $result['provider_payment_id'] ?? null;
        if ($providerPaymentId !== null && !is_string($providerPaymentId)) {
            throw new RuntimeException('Payment gateway returned an invalid transaction reference.');
        }
        if ($status === 'paid') {
            $this->finalizePayment($payment, 'paid', $providerPaymentId);
            return;
        }
        $this->database->table('payments', 'stockflow')
            ->where('id', '=', $paymentId)
            ->where('status', '=', 'pending')
            ->update([
                'provider_payment_id' => $providerPaymentId,
                'status' => $status,
                'paid_at' => null,
            ]);
    }

    public function updatePendingManualPayment(int $paymentId, string $reference): void
    {
        $reference = $this->scalarString($reference, 'Payment reference');
        if (strlen($reference) > 180) {
            throw new InvalidArgumentException('Payment reference must be 180 characters or fewer.');
        }
        $payment = $this->getPayment($paymentId);
        if ($payment === null || !$this->isOfflinePayment($payment['provider']) || $payment['status'] !== 'pending') {
            throw new InvalidArgumentException('Only pending offline payments can be edited.');
        }
        $this->database->table('payments', 'stockflow')
            ->where('id', '=', $paymentId)
            ->whereIn('provider', ['manual', 'quote', 'cash', 'purchase_order'])
            ->where('status', '=', 'pending')
            ->update(['provider_payment_id' => $reference !== '' ? $reference : null]);
    }

    public function deletePendingManualPayment(int $paymentId): void
    {
        $payment = $this->getPayment($paymentId);
        if ($payment === null || !$this->isOfflinePayment($payment['provider']) || $payment['status'] !== 'pending') {
            throw new InvalidArgumentException('Only pending offline payments can be deleted.');
        }
        $this->database->table('payments', 'stockflow')
            ->where('id', '=', $paymentId)
            ->whereIn('provider', ['manual', 'quote', 'cash', 'purchase_order'])
            ->where('status', '=', 'pending')
            ->delete();
    }

    public function receiveManualPayment(int $paymentId): void
    {
        $payment = $this->getPayment($paymentId);
        if ($payment === null || !$this->isOfflinePayment($payment['provider']) || $payment['status'] !== 'pending') {
            throw new InvalidArgumentException('Only pending offline payments can be received.');
        }
        $this->finalizePayment($payment, 'paid');
    }

    public function reconcileGatewayPayment(int $paymentId, string $providerPaymentId): void
    {
        $providerPaymentId = $this->requiredString($providerPaymentId, 'Gateway transaction ID');
        if (strlen($providerPaymentId) > 180) {
            throw new InvalidArgumentException('Gateway transaction ID must be 180 characters or fewer.');
        }
        $payment = $this->getPayment($paymentId);
        if (
            $payment === null
            || $this->isOfflinePayment($payment['provider'])
            || !in_array($payment['status'], ['pending', 'authorized'], true)
        ) {
            throw new InvalidArgumentException('This gateway payment cannot be reconciled.');
        }
        $this->finalizePayment($payment, 'paid', $providerPaymentId);
    }

    private function finalizePayment(array $payment, string $status, ?string $providerPaymentId = null): void
    {
        $this->database->transaction(function () use ($payment, $status, $providerPaymentId): void {
            $orderId = (int) $payment['order_id'];
            $order = $this->lockPendingOrder($orderId);
            if (
                !(new Price((float) $payment['amount'], (string) $payment['currency_code']))
                    ->isEqual(new Price((float) $order['grand_total'], (string) $order['currency_code']))
            ) {
                throw new InvalidArgumentException('The payment amount does not match the full order balance.');
            }
            foreach ($this->getOrderPayments($orderId) as $otherPayment) {
                if ((int) $otherPayment['id'] !== (int) $payment['id'] && $otherPayment['status'] === 'paid') {
                    throw new InvalidArgumentException('Another payment has already completed this order.');
                }
            }
            $updatedPayment = $this->database->table('payments', 'stockflow')
                ->where('id', '=', (int) $payment['id'])
                ->whereIn('status', ['pending', 'authorized'])
                ->update([
                    'provider_payment_id' => $providerPaymentId ?? $payment['provider_payment_id'] ?? null,
                    'status' => $status,
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);
            if ($updatedPayment !== 1) {
                throw new RuntimeException('Payment changed while it was being finalized.');
            }
            $items = $this->database->table('order_items', 'stockflow')
                ->where('order_id', '=', $orderId)
                ->get();
            foreach ($items as $item) {
                $inventory = $this->database->table('inventory', 'stockflow')
                    ->where('variant_id', '=', (int) $item['variant_id'])
                    ->first();
                if (
                    $inventory === null
                    || (int) $inventory['quantity_reserved'] < (int) $item['quantity']
                    || (int) $inventory['quantity_on_hand'] < (int) $item['quantity']
                ) {
                    throw new RuntimeException('Reserved inventory is inconsistent; payment was not finalized.');
                }
                $updated = $this->database->table('inventory', 'stockflow')
                    ->where('variant_id', '=', (int) $item['variant_id'])
                    ->where('quantity_on_hand', '=', (int) $inventory['quantity_on_hand'])
                    ->where('quantity_reserved', '=', (int) $inventory['quantity_reserved'])
                    ->update([
                        'quantity_on_hand' => (int) $inventory['quantity_on_hand'] - (int) $item['quantity'],
                        'quantity_reserved' => (int) $inventory['quantity_reserved'] - (int) $item['quantity'],
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Inventory changed while finalizing payment.');
                }
                $this->database->table('inventory_movements', 'stockflow')->insert([
                    'variant_id' => (int) $item['variant_id'],
                    'quantity_delta' => -(int) $item['quantity'],
                    'movement_type' => 'sale',
                    'reason' => 'Manual order ' . $order['order_number'],
                    'reference_type' => 'order',
                    'reference_id' => $orderId,
                ]);
            }
            $updatedOrder = $this->database->table('orders', 'stockflow')
                ->where('id', '=', $orderId)
                ->where('status', '=', 'pending')
                ->update(['status' => 'confirmed']);
            if ($updatedOrder !== 1) {
                throw new RuntimeException('Order changed while the payment was being finalized.');
            }
        });
    }

    private function addressById(mixed $id): ?array
    {
        if (!is_numeric($id) || (int) $id < 1) {
            return null;
        }
        return $this->database->table('addresses', 'stockflow')
            ->where('id', '=', (int) $id)
            ->first();
    }

    private function lockPendingOrder(int $orderId): array
    {
        $this->database->table('orders', 'stockflow')
            ->where('id', '=', $orderId)
            ->where('status', '=', 'pending')
            ->update(['updated_at' => date('Y-m-d H:i:s')]);
        $order = $this->database->table('orders', 'stockflow')
            ->where('id', '=', $orderId)
            ->first();
        if ($order === null || $order['status'] !== 'pending') {
            throw new InvalidArgumentException('This order is no longer pending.');
        }
        return $order;
    }

    public function createOrder(array $data, array $taxRules, array $discountRules): array
    {
        return $this->saveOrder($data, $taxRules, $discountRules, null);
    }

    public function createCheckoutOrder(
        array $data,
        array $taxRules,
        array $discountRules,
        ?\Closure $onCreated = null
    ): array
    {
        return $this->saveOrder($data, $taxRules, $discountRules, null, false, true, $onCreated);
    }

    public function previewOrder(array $data, array $taxRules, array $discountRules): array
    {
        return $this->saveOrder($data, $taxRules, $discountRules, null, true, true);
    }

    public function updatePendingOrder(int $orderId, array $data, array $taxRules, array $discountRules): array
    {
        return $this->saveOrder($data, $taxRules, $discountRules, $orderId);
    }

    private function saveOrder(
        array $data,
        array $taxRules,
        array $discountRules,
        ?int $existingOrderId,
        bool $preview = false,
        bool $autoApplyAdjustments = false,
        ?\Closure $onCreated = null
    ): array
    {
        $currency = $this->currency();
        $existingOrder = null;
        $existingQuantities = [];
        if ($existingOrderId !== null) {
            $existingOrder = $this->database->table('orders', 'stockflow')
                ->where('id', '=', $existingOrderId)
                ->first();
            if ($existingOrder === null || $existingOrder['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending orders can be edited.');
            }
            $payments = $this->database->table('payments', 'stockflow')
                ->where('order_id', '=', $existingOrderId)
                ->get();
            foreach ($payments as $payment) {
                if (!in_array($payment['status'], ['failed'], true)) {
                    throw new InvalidArgumentException('An order with a pending, authorized, or completed payment cannot be edited.');
                }
            }
            foreach ($this->database->table('order_items', 'stockflow')
                ->where('order_id', '=', $existingOrderId)
                ->get() as $existingItem) {
                $variantId = (int) $existingItem['variant_id'];
                $existingQuantities[$variantId] = ($existingQuantities[$variantId] ?? 0) + (int) $existingItem['quantity'];
            }
        }
        $email = $this->requiredString($data['email'] ?? '', 'Customer email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid customer email address.');
        }
        $customerFirstName = $this->requiredString($data['customer_first_name'] ?? '', 'Customer first name');
        $customerLastName = $this->requiredString($data['customer_last_name'] ?? '', 'Customer last name');
        $customerPhone = $this->scalarString($data['customer_phone'] ?? '', 'Customer phone');
        $requestedBillingAddressId = null;
        if (array_key_exists('billing_address_id', $data) && $data['billing_address_id'] !== null && $data['billing_address_id'] !== '') {
            $requestedBillingAddressId = filter_var($data['billing_address_id'], FILTER_VALIDATE_INT);
            if ($requestedBillingAddressId === false || $requestedBillingAddressId < 1) {
                throw new InvalidArgumentException('Select a valid saved billing address.');
            }
        }
        $customerUserId = $data['customer_user_id'] ?? null;
        if ($customerUserId !== null && (!is_int($customerUserId) || $customerUserId < 1)) {
            throw new InvalidArgumentException('Customer account is invalid.');
        }
        $paymentMethod = $this->requiredString($data['payment_method'] ?? '', 'Payment method');
        $paymentData = $this->paymentData($data);
        $itemsInput = $data['items'] ?? null;
        if (!is_array($itemsInput) || $itemsInput === []) {
            throw new InvalidArgumentException('Add at least one order item.');
        }
        $cartLines = [];
        $rawSubtotal = new Price(0.0, $currency);
        $rawTotalItems = 0;
        $rawProductIds = [];
        $rawVariantIds = [];
        foreach ($itemsInput as $itemData) {
            if (!is_array($itemData)) {
                throw new InvalidArgumentException('Order item data is invalid.');
            }
            $variantInput = $itemData['variant_id'] ?? '';
            $quantityInput = $itemData['quantity'] ?? '';
            if (
                is_scalar($variantInput)
                && is_scalar($quantityInput)
                && trim((string) $variantInput) === ''
                && trim((string) $quantityInput) === ''
            ) {
                continue;
            }
            $variantId = $this->integer($variantInput, 'Product variant');
            $quantity = $this->integer($quantityInput, 'Item quantity');
            if ($quantity < 1) {
                throw new InvalidArgumentException('Item quantity must be greater than zero.');
            }
            if (isset($rawVariantIds[$variantId])) {
                throw new InvalidArgumentException('Each product variant can appear only once; combine its quantity.');
            }
            $variant = $this->database->table('product_variants', 'stockflow')
                ->select([
                    'product_variants.id',
                    'product_variants.product_id',
                    'product_variants.sku',
                    'product_variants.price',
                    'product_variants.weight_grams',
                    'product_variants.status',
                    'products.name AS product_name',
                    'products.status AS product_status',
                    'inventory.quantity_on_hand',
                    'inventory.quantity_reserved',
                ])
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->join('inventory', 'inventory.variant_id', '=', 'product_variants.id')
                ->where('product_variants.id', '=', $variantId)
                ->first();
            if ($variant === null || $variant['status'] !== 'active' || $variant['product_status'] !== 'active') {
                throw new InvalidArgumentException('Select an active product variant.');
            }
            $available = (int) $variant['quantity_on_hand'] - (int) $variant['quantity_reserved'];
            $available += $existingQuantities[$variantId] ?? 0;
            if ($available < $quantity) {
                throw new InvalidArgumentException('Insufficient available stock for ' . $variant['sku'] . '.');
            }
            $rawVariantIds[$variantId] = true;
            $rawTotalItems += $quantity;
            $rawProductIds[(int) $variant['product_id']] = true;
            $rawSubtotal->add((new Price((float) $variant['price'], $currency))->multiply($quantity));
            $cartLines[] = [
                'input' => $itemData,
                'variant' => $variant,
                'quantity' => $quantity,
            ];
        }
        if ($cartLines === []) {
            throw new InvalidArgumentException('Add at least one order item.');
        }
        $eligibleAutomaticDiscountIds = $autoApplyAdjustments
            ? $this->eligibleAutomaticDiscountIds(
                $discountRules,
                $rawSubtotal,
                $rawTotalItems,
                count($rawProductIds)
            )
            : [];
        $items = [];
        $weightGrams = 0;
        $subtotal = new Price(0.0, $currency);
        $itemDiscountTotal = new Price(0.0, $currency);
        $itemTaxTotal = new Price(0.0, $currency);
        $totalItems = 0;
        $distinctProductIds = [];
        $itemRules = $taxRules;
        $itemDiscountRules = $discountRules;
        foreach ($cartLines as $cartLine) {
            $itemData = $cartLine['input'];
            $variant = $cartLine['variant'];
            $variantId = (int) $variant['id'];
            $quantity = $cartLine['quantity'];
            $totalItems += $quantity;
            $distinctProductIds[(int) $variant['product_id']] = true;
            $available = (int) $variant['quantity_on_hand'] - (int) $variant['quantity_reserved'];
            $available += $existingQuantities[$variantId] ?? 0;
            if ($available < $quantity) {
                throw new InvalidArgumentException('Insufficient available stock for ' . $variant['sku'] . '.');
            }
            $unitPrice = new Price((float) $variant['price'], $currency);
            $lineTotal = (new Price((float) $variant['price'], $currency))->multiply($quantity);
            $subtotal->add($lineTotal);
            $discountIds = $this->selectedRuleIds($itemData['discount_id'] ?? '', 'discounts');
            $taxIds = $this->selectedRuleIds($itemData['tax_ids'] ?? [], 'taxes');
            if ($autoApplyAdjustments) {
                $discountIds = array_values(array_unique(array_merge(
                    $discountIds,
                    $this->automaticRuleIds($itemDiscountRules, 'product', (int) $variant['product_id'], $eligibleAutomaticDiscountIds)
                )));
                $taxIds = array_values(array_unique(array_merge(
                    $taxIds,
                    $this->automaticRuleIds($itemRules, 'product', (int) $variant['product_id'])
                )));
            }
            $this->assertApplicableRules($discountIds, $itemDiscountRules, 'product', (int) $variant['product_id'], 'discount');
            $this->assertApplicableRules($taxIds, $itemRules, 'product', (int) $variant['product_id'], 'tax');
            $lineDiscounts = $this->applyRules(
                new Price($lineTotal->getAmount(), $currency),
                $discountIds,
                $itemDiscountRules,
                'discount'
            );
            $lineDiscount = (new Price($lineTotal->getAmount(), $currency))
                ->subtract($lineDiscounts['adjusted']);
            if ($lineDiscount->isGreaterThan($lineTotal)) {
                throw new InvalidArgumentException('Item discounts cannot exceed the item subtotal.');
            }
            $lineNet = $lineDiscounts['adjusted'];
            $lineTaxes = $this->applyRules($lineNet, $taxIds, $itemRules, 'tax');
            $lineTax = $lineTaxes['delta'];
            $itemDiscountTotal->add($lineDiscount);
            $itemTaxTotal->add($lineTax);
            $adjustments = array_merge($lineDiscounts['snapshots'], $lineTaxes['snapshots']);
            $weightGrams += (int) ($variant['weight_grams'] ?? 0) * $quantity;
            $items[] = [
                'variant_id' => $variantId,
                'product_id' => (int) $variant['product_id'],
                'quantity' => $quantity,
                'product_name' => $variant['product_name'],
                'sku' => $variant['sku'],
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'discount_total' => $lineDiscount->getAmount(),
                'tax_total' => $lineTax->getAmount(),
                'final_total' => $lineTaxes['adjusted'],
                'item_adjustments' => $adjustments,
            ];
        }
        if ($items === []) {
            throw new InvalidArgumentException('Add at least one order item.');
        }
        $shippingMethodId = $this->requiredString($data['shipping_method'] ?? '', 'Shipping method');
        $shipping = $this->shippingMethods->calculate($shippingMethodId, $subtotal, $weightGrams);
        $overallDiscountIds = $this->selectedRuleIds($data['order_discount_id'] ?? '', 'discounts');
        $overallTaxIds = $this->selectedRuleIds($data['order_tax_ids'] ?? [], 'taxes');
        if ($autoApplyAdjustments) {
            $overallDiscountIds = array_values(array_unique(array_merge(
                $overallDiscountIds,
                $this->automaticRuleIds($itemDiscountRules, 'order_subtotal', null, $eligibleAutomaticDiscountIds)
            )));
            $overallTaxIds = array_values(array_unique(array_merge(
                $overallTaxIds,
                $this->automaticRuleIds($itemRules, 'order_subtotal')
            )));
        }
        $selectedDiscountIds = $overallDiscountIds;
        foreach ($itemsInput as $itemData) {
            if (is_array($itemData)) {
                $selectedDiscountIds = array_merge(
                    $selectedDiscountIds,
                    $this->selectedRuleIds($itemData['discount_id'] ?? '', 'discounts')
                );
            }
        }
        $this->assertDiscountEligibility(
            array_values(array_unique($selectedDiscountIds)),
            $itemDiscountRules,
            $subtotal,
            $totalItems,
            count($distinctProductIds)
        );
        $this->assertApplicableRules($overallDiscountIds, $itemDiscountRules, 'order_subtotal', null, 'discount');
        $this->assertApplicableRules($overallTaxIds, $itemRules, 'order_subtotal', null, 'tax');
        $overallDiscountBase = (new Price($subtotal->getAmount(), $currency))->subtract($itemDiscountTotal);
        $overallDiscounts = $this->applyRules(
            $overallDiscountBase,
            $overallDiscountIds,
            $itemDiscountRules,
            'discount'
        );
        $overallDiscount = $overallDiscountBase->subtract($overallDiscounts['adjusted']);
        $discount = (new Price($itemDiscountTotal->getAmount(), $currency))->add($overallDiscount);
        $overallTaxBase = (new Price($subtotal->getAmount(), $currency))
            ->subtract($discount);
        $overallTaxes = $this->applyRules($overallTaxBase, $overallTaxIds, $itemRules, 'tax');
        $tax = (new Price($itemTaxTotal->getAmount(), $currency))->add($overallTaxes['delta']);
        $grandTotal = (new Price($subtotal->getAmount(), $currency))
            ->subtract($discount)
            ->add($shipping)
            ->add($tax);
        if ($discount->isGreaterThan($subtotal)) {
            throw new InvalidArgumentException('Discount cannot exceed the order subtotal.');
        }
        if (!$grandTotal->isGreaterThan(0.0)) {
            throw new InvalidArgumentException('Order total must be greater than zero.');
        }

        $billingAddress = $this->optionalAddress($data, 'billing');
        if ($shippingMethodId !== 'pickup' && $billingAddress === null) {
            throw new InvalidArgumentException('Billing address is required for delivery orders.');
        }
        $shippingAddress = $shippingMethodId === 'pickup'
            ? null
            : (($data['same_shipping'] ?? '') === 'yes'
                ? $billingAddress
                : $this->optionalAddress($data, 'shipping'));
        $sameShipping = ($data['same_shipping'] ?? '') === 'yes';
        if ($shippingMethodId !== 'pickup' && $shippingAddress === null) {
            throw new InvalidArgumentException('Shipping address is required for delivery orders.');
        }
        $customer = $this->database->table('customers', 'stockflow')
            ->where('email', '=', $email)
            ->first();
        if ($customer !== null && $customer['status'] === 'blocked') {
            throw new InvalidArgumentException('Orders cannot be created for a blocked customer.');
        }
        if (
            $customerUserId !== null
            && $customer !== null
            && $customer['user_id'] !== null
            && (int) $customer['user_id'] !== $customerUserId
        ) {
            throw new InvalidArgumentException('This customer email is linked to a different account. Use the email address for your account.');
        }
        if ($requestedBillingAddressId !== null) {
            $savedAddress = $customer === null
                ? null
                : $this->database->table('addresses', 'stockflow')
                    ->select(['id'])
                    ->where('id', '=', (int) $requestedBillingAddressId)
                    ->where('customer_id', '=', (int) $customer['id'])
                    ->first();
            if ($savedAddress === null && $customerUserId !== null) {
                $savedAddress = $this->database->table('addresses', 'stockflow')
                    ->select(['id'])
                    ->where('id', '=', (int) $requestedBillingAddressId)
                    ->where('user_id', '=', $customerUserId)
                    ->first();
            }
            if ($savedAddress === null) {
                throw new InvalidArgumentException('The selected address does not belong to this customer.');
            }
        }
        $orderNumber = $existingOrder['order_number'] ?? ('SF-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4))));

        if ($preview) {
            $allItemAdjustments = [];
            foreach ($items as $item) {
                $allItemAdjustments = array_merge($allItemAdjustments, $item['item_adjustments']);
            }
            $itemReviews = array_map(static fn (array $item): array => [
                'variant_id' => $item['variant_id'],
                'product_id' => $item['product_id'],
                'sku' => $item['sku'],
                'name' => $item['product_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $item['line_total'],
                'discount' => new Price((float) $item['discount_total'], $currency),
                'tax' => new Price((float) $item['tax_total'], $currency),
                'total' => $item['final_total'],
                'discounts' => array_values(array_filter(
                    $item['item_adjustments'],
                    static fn (array $adjustment): bool => ($adjustment['kind'] ?? '') === 'discount'
                )),
                'taxes' => array_values(array_filter(
                    $item['item_adjustments'],
                    static fn (array $adjustment): bool => ($adjustment['kind'] ?? '') === 'tax'
                )),
            ], $items);
            return [
                'currency' => $currency,
                'customer' => [
                    'email' => $email,
                    'first_name' => $customerFirstName,
                    'last_name' => $customerLastName,
                    'phone' => $customerPhone,
                ],
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'payment_method' => $paymentMethod,
                'shipping_method' => $shippingMethodId,
                'items' => $itemReviews,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping' => $shipping,
                'tax' => $tax,
                'total' => $grandTotal,
                'discounts' => array_values(array_filter(
                    array_merge($allItemAdjustments, $overallDiscounts['snapshots']),
                    static fn (array $adjustment): bool => ($adjustment['kind'] ?? '') === 'discount'
                )),
                'taxes' => array_values(array_filter(
                    array_merge($allItemAdjustments, $overallTaxes['snapshots']),
                    static fn (array $adjustment): bool => ($adjustment['kind'] ?? '') === 'tax'
                )),
            ];
        }

        $orderId = $this->database->transaction(function () use (
            $items,
            $currency,
            $email,
            $customerFirstName,
            $customerLastName,
            $customerPhone,
            $customerUserId,
            $requestedBillingAddressId,
            $sameShipping,
            $customer,
            $billingAddress,
            $shippingAddress,
            $subtotal,
            $discount,
            $shipping,
            $tax,
            $grandTotal,
            $orderNumber,
            $shippingMethodId,
            $paymentMethod,
            $overallDiscounts,
            $overallTaxes,
            $existingOrderId,
            $existingOrder,
            $existingQuantities,
            $onCreated
        ): int {
            if ($existingOrderId !== null) {
                $this->lockPendingOrder($existingOrderId);
                foreach ($this->getOrderPayments($existingOrderId) as $payment) {
                    if ($payment['status'] !== 'failed') {
                        throw new InvalidArgumentException('An order with a pending, authorized, or completed payment cannot be edited.');
                    }
                }
                $existingQuantities = [];
                foreach ($this->database->table('order_items', 'stockflow')
                    ->where('order_id', '=', $existingOrderId)
                    ->get() as $existingItem) {
                    $variantId = (int) $existingItem['variant_id'];
                    $existingQuantities[$variantId] = ($existingQuantities[$variantId] ?? 0)
                        + (int) $existingItem['quantity'];
                }
            }
            if ($customer === null) {
                $customerId = $this->database->table('customers', 'stockflow')->insert([
                    'user_id' => $customerUserId,
                    'email' => $email,
                    'first_name' => $customerFirstName,
                    'last_name' => $customerLastName,
                    'phone' => $customerPhone !== '' ? $customerPhone : null,
                    'status' => 'guest',
                    'accepts_marketing' => 0,
                ]);
            } else {
                $customerId = $customer['id'];
                if ($customerUserId !== null && $customer['user_id'] === null) {
                    $linked = $this->database->table('customers', 'stockflow')
                        ->where('id', '=', (int) $customerId)
                        ->where('user_id', 'IS NULL')
                        ->update(['user_id' => $customerUserId]);
                    if ($linked !== 1) {
                        throw new InvalidArgumentException('The customer record could not be linked to your account. Please try again.');
                    }
                }
            }
            if ($billingAddress === null) {
                $billingAddressId = null;
            } elseif ($requestedBillingAddressId !== null) {
                $savedAddress = $this->database->table('addresses', 'stockflow')
                    ->select(['id'])
                    ->where('id', '=', (int) $requestedBillingAddressId)
                    ->where('customer_id', '=', (int) $customerId)
                    ->first();
                if ($savedAddress === null && $customerUserId !== null) {
                    $savedAddress = $this->database->table('addresses', 'stockflow')
                        ->select(['id'])
                        ->where('id', '=', (int) $requestedBillingAddressId)
                        ->where('user_id', '=', $customerUserId)
                        ->first();
                }
                if ($savedAddress === null) {
                    throw new InvalidArgumentException('The selected address does not belong to this customer.');
                }
                $billingAddressId = (int) $savedAddress['id'];
            } else {
                $billingAddressId = $this->insertAddress($billingAddress, $customerId);
            }
            if ($shippingAddress === null) {
                $shippingAddressId = null;
            } elseif ($sameShipping) {
                $shippingAddressId = $billingAddressId;
            } else {
                $shippingAddressId = $this->insertAddress($shippingAddress, $customerId);
            }
            $orderAdjustments = array_merge($overallDiscounts['snapshots'], $overallTaxes['snapshots']);
            $orderAdjustments[] = [
                'kind' => 'shipping_method',
                'id' => $shippingMethodId,
            ];
            $orderValues = [
                'customer_id' => $customerId,
                'email' => $email,
                'currency_code' => $currency,
                'subtotal' => $subtotal->getAmount(),
                'discount_total' => $discount->getAmount(),
                'shipping_total' => $shipping->getAmount(),
                'tax_total' => $tax->getAmount(),
                'grand_total' => $grandTotal->getAmount(),
                'order_adjustments' => json_encode($orderAdjustments, JSON_THROW_ON_ERROR),
                'shipping_address_id' => $shippingAddressId,
                'billing_address_id' => $billingAddressId,
                'placed_at' => $existingOrder['placed_at'] ?? date('Y-m-d H:i:s'),
            ];
            if ($existingOrderId === null) {
                $orderId = $this->database->table('orders', 'stockflow')->insert([
                    'order_number' => $orderNumber,
                    'status' => 'pending',
                    ...$orderValues,
                ]);
            } else {
                $orderId = $existingOrderId;
                $oldVariants = $existingQuantities;
                $newVariants = [];
                foreach ($items as $item) {
                    $variantId = (int) $item['variant_id'];
                    $newVariants[$variantId] = ($newVariants[$variantId] ?? 0) + (int) $item['quantity'];
                }
                foreach (array_unique(array_merge(array_keys($oldVariants), array_keys($newVariants))) as $variantId) {
                    $inventory = $this->database->table('inventory', 'stockflow')
                        ->where('variant_id', '=', $variantId)
                        ->first();
                    $oldQuantity = $oldVariants[$variantId] ?? 0;
                    $newQuantity = $newVariants[$variantId] ?? 0;
                    if ($inventory === null || (int) $inventory['quantity_reserved'] < $oldQuantity) {
                        throw new RuntimeException('Reserved order inventory is inconsistent; the order was not updated.');
                    }
                    $availableExcludingOrder = (int) $inventory['quantity_on_hand']
                        - ((int) $inventory['quantity_reserved'] - $oldQuantity);
                    if ($availableExcludingOrder < $newQuantity) {
                        throw new InvalidArgumentException('Insufficient available stock to update this order.');
                    }
                    $newReserved = (int) $inventory['quantity_reserved'] - $oldQuantity + $newQuantity;
                    $updated = $this->database->table('inventory', 'stockflow')
                        ->where('variant_id', '=', $variantId)
                        ->where('quantity_reserved', '=', (int) $inventory['quantity_reserved'])
                        ->update(['quantity_reserved' => $newReserved]);
                    if ($updated !== 1) {
                        throw new RuntimeException('Inventory changed while updating the order reservation.');
                    }
                }
                $this->database->table('order_items', 'stockflow')
                    ->where('order_id', '=', $orderId)
                    ->delete();
                $this->database->table('orders', 'stockflow')
                    ->where('id', '=', $orderId)
                    ->where('status', '=', 'pending')
                    ->update($orderValues);
            }
            foreach ($items as $item) {
                if ($existingOrderId === null) {
                    $inventory = $this->database->table('inventory', 'stockflow')
                        ->where('variant_id', '=', $item['variant_id'])
                        ->first();
                    if ($inventory === null
                        || (int) $inventory['quantity_on_hand'] - (int) $inventory['quantity_reserved'] < $item['quantity']) {
                        throw new InvalidArgumentException('Stock changed while creating the order. Review quantities and retry.');
                    }
                    $updated = $this->database->table('inventory', 'stockflow')
                        ->where('variant_id', '=', $item['variant_id'])
                        ->where('quantity_reserved', '=', (int) $inventory['quantity_reserved'])
                        ->update(['quantity_reserved' => (int) $inventory['quantity_reserved'] + $item['quantity']]);
                    if ($updated !== 1) {
                        throw new InvalidArgumentException('Stock changed while creating the order. Review quantities and retry.');
                    }
                }
                $this->database->table('order_items', 'stockflow')->insert([
                    'order_id' => $orderId,
                    'variant_id' => $item['variant_id'],
                    'product_name' => $item['product_name'],
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price']->getAmount(),
                    'discount_total' => $item['discount_total'],
                    'line_total' => $item['final_total']->getAmount(),
                    'item_adjustments' => json_encode($item['item_adjustments'], JSON_THROW_ON_ERROR),
                ]);
            }
            if ($existingOrderId === null) {
                $this->database->table('payments', 'stockflow')->insert([
                    'order_id' => $orderId,
                    'provider' => $paymentMethod,
                    'status' => 'pending',
                    'amount' => $grandTotal->getAmount(),
                    'currency_code' => $currency,
                ]);
                $onCreated?->__invoke($orderId);
            }
            return $orderId;
        });

        return [
            'id' => $orderId,
            'order_number' => $orderNumber,
            'payment_method' => $paymentMethod,
            'currency' => $currency,
            'amount' => $grandTotal,
            'payment_data' => $paymentData,
        ];
    }

    public function recordPaymentResult(int $orderId, array $result): void
    {
        $status = $result['status'] ?? '';
        if (!in_array($status, ['pending', 'authorized', 'paid', 'failed'], true)) {
            throw new InvalidArgumentException('Payment gateway returned an unsupported status.');
        }
        $this->database->transaction(function () use ($orderId, $result, $status): void {
            $order = $this->lockPendingOrder($orderId);
            $payment = $this->database->table('payments', 'stockflow')
                ->where('order_id', '=', $orderId)
                ->first();
            if ($payment === null) {
                throw new RuntimeException('Pending payment record is missing for the manual order.');
            }
            $providerPaymentId = $result['provider_payment_id'] ?? null;
            if ($providerPaymentId !== null && !is_string($providerPaymentId)) {
                throw new RuntimeException('Payment gateway returned an invalid transaction reference.');
            }
            $updatedPayment = $this->database->table('payments', 'stockflow')
                ->where('id', '=', $payment['id'])
                ->where('status', '=', 'pending')
                ->update([
                    'provider_payment_id' => $providerPaymentId,
                    'status' => $status,
                    'paid_at' => $status === 'paid' ? date('Y-m-d H:i:s') : null,
                ]);
            if ($updatedPayment !== 1) {
                throw new RuntimeException('Payment changed while its result was being recorded.');
            }

            $items = $this->database->table('order_items', 'stockflow')
                ->where('order_id', '=', $orderId)
                ->get();
            foreach ($items as $item) {
                $inventory = $this->database->table('inventory', 'stockflow')
                    ->where('variant_id', '=', $item['variant_id'])
                    ->first();
                if ($inventory === null || (int) $inventory['quantity_reserved'] < (int) $item['quantity']) {
                    throw new RuntimeException('Reserved order inventory is inconsistent; payment status was not fully applied.');
                }
                if ($status === 'paid') {
                    if ((int) $inventory['quantity_on_hand'] < (int) $item['quantity']) {
                        throw new RuntimeException('On-hand inventory is insufficient to confirm this paid order.');
                    }
                    $updated = $this->database->table('inventory', 'stockflow')
                        ->where('variant_id', '=', $item['variant_id'])
                        ->where('quantity_on_hand', '=', (int) $inventory['quantity_on_hand'])
                        ->where('quantity_reserved', '=', (int) $inventory['quantity_reserved'])
                        ->update([
                            'quantity_on_hand' => (int) $inventory['quantity_on_hand'] - (int) $item['quantity'],
                            'quantity_reserved' => (int) $inventory['quantity_reserved'] - (int) $item['quantity'],
                        ]);
                    if ($updated !== 1) {
                        throw new RuntimeException('Inventory changed while confirming the paid order.');
                    }
                    $this->database->table('inventory_movements', 'stockflow')->insert([
                        'variant_id' => $item['variant_id'],
                        'quantity_delta' => -(int) $item['quantity'],
                        'movement_type' => 'sale',
                        'reason' => 'Manual order ' . $order['order_number'],
                        'reference_type' => 'order',
                        'reference_id' => $orderId,
                    ]);
                } elseif ($status === 'failed') {
                    $updated = $this->database->table('inventory', 'stockflow')
                        ->where('variant_id', '=', $item['variant_id'])
                        ->where('quantity_reserved', '=', (int) $inventory['quantity_reserved'])
                        ->update(['quantity_reserved' => (int) $inventory['quantity_reserved'] - (int) $item['quantity']]);
                    if ($updated !== 1) {
                        throw new RuntimeException('Inventory changed while releasing the failed order reservation.');
                    }
                }
            }
            $this->database->table('orders', 'stockflow')
                ->where('id', '=', $orderId)
                ->where('status', '=', 'pending')
                ->update(['status' => match ($status) {
                    'paid' => 'confirmed',
                    'authorized' => 'pending',
                    'failed' => 'cancelled',
                    default => 'pending',
                }]);
        });
    }

    private function address(array $data, string $prefix): array
    {
        $firstName = $this->scalarString($data[$prefix . '_first_name'] ?? '', ucfirst($prefix) . ' first name');
        $lastName = $this->scalarString($data[$prefix . '_last_name'] ?? '', ucfirst($prefix) . ' last name');
        if ($firstName === '') {
            $firstName = $this->requiredString($data['customer_first_name'] ?? '', 'Customer first name');
        }
        if ($lastName === '') {
            $lastName = $this->requiredString($data['customer_last_name'] ?? '', 'Customer last name');
        }
        $address = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => $this->scalarString($data[$prefix . '_company'] ?? '', ucfirst($prefix) . ' company'),
            'line1' => $this->requiredString($data[$prefix . '_line1'] ?? '', ucfirst($prefix) . ' address line 1'),
            'line2' => $this->scalarString($data[$prefix . '_line2'] ?? '', ucfirst($prefix) . ' address line 2'),
            'city' => $this->requiredString($data[$prefix . '_city'] ?? '', ucfirst($prefix) . ' city'),
            'state' => $this->scalarString($data[$prefix . '_state'] ?? '', ucfirst($prefix) . ' region'),
            'postal_code' => $this->requiredString($data[$prefix . '_postal_code'] ?? '', ucfirst($prefix) . ' postal code'),
            'country_code' => strtoupper($this->requiredString($data[$prefix . '_country_code'] ?? '', ucfirst($prefix) . ' country code')),
            'phone' => $this->scalarString($data[$prefix . '_phone'] ?? '', ucfirst($prefix) . ' phone'),
        ];
        if (!preg_match('/^[A-Z]{2}$/', $address['country_code'])) {
            throw new InvalidArgumentException(ucfirst($prefix) . ' country must be a two-letter ISO code.');
        }
        return $address;
    }

    private function optionalAddress(array $data, string $prefix): ?array
    {
        foreach ([
            'first_name', 'last_name', 'company', 'line1', 'line2', 'city',
            'state', 'postal_code', 'country_code',
        ] as $field) {
            $value = $data[$prefix . '_' . $field] ?? '';
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(ucfirst($prefix) . ' address fields must be single values.');
            }
            if (trim((string) ($value ?? '')) !== '') {
                return $this->address($data, $prefix);
            }
        }
        return null;
    }

    private function insertAddress(array $address, mixed $customerId): int
    {
        return $this->database->table('addresses', 'stockflow')->insert([
            'customer_id' => $customerId,
            'label' => 'Order address',
            ...$address,
        ]);
    }

    private function paymentData(array $data): array
    {
        $paymentData = $data['payment_data'] ?? [];
        if (!is_array($paymentData)) {
            throw new InvalidArgumentException('Payment details must be a structured form value.');
        }
        foreach ($paymentData as $key => $value) {
            if (!is_string($value) || strlen($value) > 4000) {
                throw new InvalidArgumentException('Payment detail ' . $key . ' is invalid.');
            }
            $paymentData[$key] = trim($value);
        }
        return $paymentData;
    }

    private function selectedRuleIds(mixed $value, string $kind): array
    {
        if ($value === '' || $value === null) {
            return [];
        }
        if ($kind === 'discounts' && is_string($value)) {
            $id = trim($value);
            return $id === '' ? [] : [$id];
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Selected ' . $kind . ' must be a list.');
        }
        $ids = [];
        foreach ($value as $id) {
            if (is_string($id)) {
                $id = trim($id);
            }
            if ($id === '') {
                continue;
            }
            if (!is_string($id) || strlen($id) > 40) {
                throw new InvalidArgumentException('Selected ' . $kind . ' contain an invalid rule.');
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function applyRules(Price $base, array $ids, array $rules, string $kind): array
    {
        $byId = [];
        foreach ($rules as $rule) {
            $byId[(string) ($rule['id'] ?? '')] = $rule;
        }
        $currency = $base->getCurrency()->getCode();
        $adjusted = new Price($base->getAmount(), $currency);
        $snapshots = [];
        foreach ($ids as $id) {
            $rule = $byId[$id] ?? null;
            if ($rule === null) {
                throw new InvalidArgumentException('A selected ' . $kind . ' rule is no longer active.');
            }
            $type = (string) ($rule['calculation_type'] ?? '');
            $amount = (float) ($rule['amount'] ?? 0);
            $signedAmount = $kind === 'discount' ? -$amount : $amount;
            $adjustment = new Adjustment(
                (string) $rule['name'],
                (string) $rule['description'],
                $signedAmount,
                $type
            );
            $before = new Price($adjusted->getAmount(), $currency);
            $adjustment->applyTo($adjusted);
            if ($adjusted->isLessThan(0.0) || !is_finite($adjusted->getAmount())) {
                throw new InvalidArgumentException('Applied discounts cannot reduce an amount below zero.');
            }
            $appliedAmount = $kind === 'discount'
                ? (new Price($before->getAmount(), $currency))->subtract($adjusted)
                : (new Price($adjusted->getAmount(), $currency))->subtract($before);
            $snapshots[] = [
                'id' => $id,
                'name' => (string) $rule['name'],
                'description' => (string) $rule['description'],
                'value' => $signedAmount,
                'type' => $type,
                'kind' => $kind,
                'applied_amount' => $appliedAmount->format(false),
                'currency' => $currency,
                'apply_to' => $rule['apply_to'] ?? 'legacy',
                'product_id' => $rule['product_id'] ?? null,
            ];
        }
        $before = new Price($base->getAmount(), $currency);
        if ($adjusted->isLessThan(0.0) || !is_finite($adjusted->getAmount())) {
            throw new InvalidArgumentException('Applied discounts cannot reduce an amount below zero.');
        }
        $delta = (new Price($adjusted->getAmount(), $currency))->subtract($before);
        return [
            'adjusted' => $adjusted,
            'delta' => $delta,
            'snapshots' => $snapshots,
        ];
    }

    private function automaticRuleIds(
        array $rules,
        string $scope,
        ?int $productId = null,
        ?array $eligibleDiscountIds = null
    ): array {
        $ids = [];
        foreach ($rules as $rule) {
            $id = (string) ($rule['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $applyTo = (string) ($rule['apply_to'] ?? 'legacy');
            $applicable = $scope === 'product'
                ? $applyTo === 'all_products'
                    || ($applyTo === 'product' && (int) ($rule['product_id'] ?? 0) === $productId)
                : in_array($applyTo, ['order_subtotal', 'legacy'], true);
            if (!$applicable) {
                continue;
            }
            if ($eligibleDiscountIds !== null && !in_array($id, $eligibleDiscountIds, true)) {
                continue;
            }
            $ids[] = $id;
        }
        return $ids;
    }

    private function eligibleAutomaticDiscountIds(
        array $rules,
        Price $subtotal,
        int $totalItems,
        int $distinctProducts
    ): array {
        $eligible = [];
        $currency = $subtotal->getCurrency()->getCode();
        $today = date('Y-m-d');
        foreach ($rules as $rule) {
            $id = (string) ($rule['id'] ?? '');
            if (
                $id === ''
                || (!empty($rule['starts_at']) && $today < $rule['starts_at'])
                || (!empty($rule['ends_at']) && $today > $rule['ends_at'])
                || (isset($rule['minimum_order_total'])
                    && $subtotal->isLessThan(new Price((float) $rule['minimum_order_total'], $currency)))
                || (isset($rule['maximum_order_total'])
                    && $subtotal->isGreaterThan(new Price((float) $rule['maximum_order_total'], $currency)))
                || (isset($rule['minimum_total_items']) && $totalItems < (int) $rule['minimum_total_items'])
                || (isset($rule['maximum_total_items']) && $totalItems > (int) $rule['maximum_total_items'])
                || (isset($rule['minimum_distinct_products']) && $distinctProducts < (int) $rule['minimum_distinct_products'])
                || (isset($rule['maximum_distinct_products']) && $distinctProducts > (int) $rule['maximum_distinct_products'])
            ) {
                continue;
            }
            $eligible[] = $id;
        }
        return $eligible;
    }

    private function assertApplicableRules(
        array $ids,
        array $rules,
        string $scope,
        ?int $productId,
        string $kind
    ): void {
        $rulesById = [];
        foreach ($rules as $rule) {
            $rulesById[(string) ($rule['id'] ?? '')] = $rule;
        }
        foreach ($ids as $id) {
            $rule = $rulesById[$id] ?? null;
            if ($rule === null) {
                throw new InvalidArgumentException('A selected ' . $kind . ' rule is no longer active.');
            }
            $applyTo = (string) ($rule['apply_to'] ?? 'legacy');
            $applicable = $applyTo === 'legacy'
                || ($scope === 'product' && $applyTo === 'all_products')
                || ($scope === 'product' && $applyTo === 'product'
                    && (int) ($rule['product_id'] ?? 0) === $productId)
                || ($scope === 'order_subtotal' && $applyTo === 'order_subtotal');
            if (!$applicable) {
                throw new InvalidArgumentException('The selected ' . $kind . ' rule does not apply to this ' . ($scope === 'product' ? 'product.' : 'order subtotal.'));
            }
        }
    }

    private function assertDiscountEligibility(
        array $ids,
        array $rules,
        Price $orderSubtotal,
        int $totalItems,
        int $distinctProducts
    ): void {
        $rulesById = [];
        foreach ($rules as $rule) {
            $rulesById[(string) ($rule['id'] ?? '')] = $rule;
        }
        $currency = $orderSubtotal->getCurrency()->getCode();
        foreach ($ids as $id) {
            $rule = $rulesById[$id] ?? null;
            if ($rule === null) {
                throw new InvalidArgumentException('A selected discount rule is no longer active.');
            }
            $today = date('Y-m-d');
            if (
                (!empty($rule['starts_at']) && $today < $rule['starts_at'])
                || (!empty($rule['ends_at']) && $today > $rule['ends_at'])
            ) {
                throw new InvalidArgumentException('Discount "' . $rule['name'] . '" is not currently valid.');
            }
            if (
                isset($rule['minimum_order_total'])
                && $orderSubtotal->isLessThan(new Price((float) $rule['minimum_order_total'], $currency))
            ) {
                throw new InvalidArgumentException('Order subtotal does not meet discount "' . $rule['name'] . '" minimum.');
            }
            if (
                isset($rule['maximum_order_total'])
                && $orderSubtotal->isGreaterThan(new Price((float) $rule['maximum_order_total'], $currency))
            ) {
                throw new InvalidArgumentException('Order subtotal exceeds discount "' . $rule['name'] . '" maximum.');
            }
            if (isset($rule['minimum_total_items']) && $totalItems < (int) $rule['minimum_total_items']) {
                throw new InvalidArgumentException('Order quantity does not meet discount "' . $rule['name'] . '" minimum.');
            }
            if (isset($rule['maximum_total_items']) && $totalItems > (int) $rule['maximum_total_items']) {
                throw new InvalidArgumentException('Order quantity exceeds discount "' . $rule['name'] . '" maximum.');
            }
            if (isset($rule['minimum_distinct_products']) && $distinctProducts < (int) $rule['minimum_distinct_products']) {
                throw new InvalidArgumentException('Order does not contain enough distinct products for discount "' . $rule['name'] . '".');
            }
            if (isset($rule['maximum_distinct_products']) && $distinctProducts > (int) $rule['maximum_distinct_products']) {
                throw new InvalidArgumentException('Order contains too many distinct products for discount "' . $rule['name'] . '".');
            }
        }
    }

    private function optionalMoney(mixed $value, string $currency, string $label): Price
    {
        $input = $this->scalarString($value, $label);
        if ($input === '') {
            return new Price(0.0, $currency);
        }
        if (!is_numeric($input) || !is_finite((float) $input) || (float) $input < 0) {
            throw new InvalidArgumentException($label . ' must be a valid non-negative amount.');
        }
        return new Price((float) $input, $currency);
    }

    private function currency(): string
    {
        $currency = strtoupper((string) (
            $this->settings->getSetting('stockflow.settings')?->get('currency')
            ?? getenv('STOCKFLOW_CURRENCY')
            ?: 'USD'
        ));
        new Currency($currency);
        return $currency;
    }

    private function formatMoney(mixed $amount, string $currency): string
    {
        return (new Price((float) $amount, $currency))->format(false);
    }

    private function isOfflinePayment(mixed $provider): bool
    {
        return is_string($provider) && in_array(
            $provider,
            ['manual', 'quote', 'cash', 'purchase_order'],
            true
        );
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return trim($value);
    }

    private function scalarString(mixed $value, string $label): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException($label . ' must be a single value.');
        }
        return trim((string) ($value ?? ''));
    }

    private function integer(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new InvalidArgumentException($label . ' must be a whole number.');
        }
        return $integer;
    }
}
