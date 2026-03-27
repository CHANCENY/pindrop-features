<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Order;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\DatabaseException;

class Calculator
{

    protected OrderManager $orderManager;

    public function __construct()
    {
        $container = \getAppContainer();
        $this->orderManager = $container->get('commerce_store.order_manager');
    }

    /**
     * @throws DatabaseException
     */
    public function reCalculateOrder(int $order)
    {
        $orderData = $this->orderManager->getCompleteOrder($order);
        $payments = $this->orderManager->payment()->getPaymentsByOrder($order);

        $totalPaid = 0;

        foreach ($payments as $payment) {
            if ($payment['status'] == 'completed') {
                $totalPaid += floatval($payment['amount']);
            }
        }

        $totalAmount = 0;
        $subtotal    = 0;
        $totalTaxAmount = 0;
        $shippingAmount = 0;
        $discountAmount = 0;
        $weightTotal    = 0;
        $othersTotal    = 0;

        $adjustment_added = [];

        foreach (is_array($orderData['items']) ? $orderData['items'] : [] as $item) {

            $unit_price   = (float) $item['unit_price'] ?? 0;
            $quantity     = (int) $item['quantity'] ?? 0;
            $discount     = (float) $item['discount_amount'] ?? 0;
            $weight       = (float) $item['weight'] ?? 0;
            $taxAmount    = (float) $item['tax_amount'] ?? 0;

            $subtotal         += $unit_price * $quantity;
            $weightTotal      += $weight * $quantity;
            $discountAmount   += $discount * $quantity;
            $totalTaxAmount   += $taxAmount * $quantity;


        }

        foreach (is_array($orderData['order']['adjustments']) ? $orderData['order']['adjustments'] : [] as $adjustment) {

            if ($adjustment['type'] == 'discount') {
                $discountAmount += $adjustment['amount'];
            }
            elseif ($adjustment['type'] == 'tax') {
                $totalTaxAmount += $adjustment['amount'];
            }
            elseif ($adjustment['type'] !== 'shipping') {
                $othersTotal += $adjustment['amount'];
            }

        }

        $shippingMethod = "free.shipping.method";
        foreach (is_array($orderData['order']['adjustments']) ? $orderData['order']['adjustments'] : [] as $adjustment) {
            if ($adjustment['type'] === 'shipping') {
                $shippingMethod = $adjustment['source'] ?? "free.shipping.method";
            }
        }

        $shippingAmount = $this->calculateShippingCost($orderData['items'] ?? [], $shippingMethod);

        foreach (is_array($orderData['order']['adjustments']) ? $orderData['order']['adjustments'] : [] as $k=>$adjustment) {
            if ($adjustment['type'] === 'shipping') {
                $orderData['order']['adjustments'][$k]['amount'] = $shippingAmount;
            }
        }

        $orderColumns  =  $orderData['order'] ?? [];
        $orderColumns['subtotal'] = $subtotal;
        $orderColumns['tax_amount'] = $totalTaxAmount;
        $orderColumns['discount_amount'] = $discountAmount;
        $orderColumns['shipping_amount'] = $shippingAmount;
        $orderColumns['total_amount'] = $subtotal + $totalTaxAmount + $shippingAmount - $discountAmount + $othersTotal;

        if ($totalPaid === $orderColumns['total_amount']) {
            $orderColumns['payment_status'] = 'completed';
        }
        elseif ($totalPaid < $orderColumns['total_amount'] && $totalPaid !== 0) {
            $orderColumns['payment_status'] = 'processing';
        }
        elseif ($totalPaid <= 0) {
            $orderColumns['payment_status'] = 'pending';
        }
        elseif ($totalPaid > $orderColumns['total_amount']) {
            $orderColumns['payment_status'] = 'completed';
        }

        $id = $orderColumns['id'];
        unset($orderColumns['id']);

        foreach ($orderColumns['adjustments'] ?? [] as $k=>$adjustment) {

            if (!in_array($adjustment['type'], $adjustment_added)) {
                $adjustment_added[] = $adjustment['type'];
            }
            else {
                if ($adjustment['type'] === 'shipping') {
                    unset($orderColumns['adjustments'][$k]);
                }
            }
        }

        return $this->orderManager->order()->updateOrder($id, $orderColumns);
    }


    public function calculateShippingCost(array $orderItems, string $shippingMethodCode): float
    {
        $shippingMethod = $this->orderManager
            ->shippingManager()
            ->getShippingMethod($shippingMethodCode);

        if (!$shippingMethod || $shippingMethod['status'] !== 'open') {
            return 0.00;
        }

        if ($shippingMethodCode === 'free.shipping.method') return 0;

        $flatRate = (float) $shippingMethod['flat_rate'];
        $dynamicPrice = (float) $shippingMethod['dynamic_price'];
        $maxWeight = (float) $shippingMethod['applicable_max_weight'];
        $overMaxPrice = (float) $shippingMethod['price_aply_over_max_weight'];
        $weightUnit = strtolower($shippingMethod['weight_unit']);
        $dimensionPriceRaw = $shippingMethod['dimension_price'];

        $totalWeightOz = 0;
        $totalDimensionArea = 0;

        foreach ($orderItems as $item) {

            if (!empty($item['virtual'])) {
                continue; // skip virtual items
            }

            $qty = (int) $item['quantity'];

            // -------------------------
            // Weight calculation
            // -------------------------
            $weight = (float) $item['weight'];

            // Convert to oz if needed
            $weightOz = $this->convertToOunces($weight, $weightUnit);

            $totalWeightOz += ($weightOz * $qty);

            // -------------------------
            // Dimension area calculation
            // -------------------------
            $length = (float) $item['dimensions_length'];
            $width  = (float) $item['dimensions_width'];

            $area = $length * $width;
            $totalDimensionArea += ($area * $qty);
        }

        $shippingCost = 0;

        // --------------------------------
        // Base Shipping Calculation
        // --------------------------------
        if ($dynamicPrice == 0) {
            $shippingCost = $flatRate;
        } else {
            $shippingCost = $flatRate + ($dynamicPrice * $totalWeightOz);
        }

        // --------------------------------
        // Over max weight charge
        // --------------------------------
        if ($totalWeightOz > $maxWeight) {
            $extraWeight = $totalWeightOz - $maxWeight;
            $shippingCost += ($extraWeight * $overMaxPrice);
        }

        // --------------------------------
        // Dimension price calculation
        // --------------------------------
        if (!empty($dimensionPriceRaw) && str_contains($dimensionPriceRaw, '/')) {
            [$amount, $areaUnit] = explode('/', $dimensionPriceRaw);

            $amount = (float) $amount;
            $areaUnit = (float) $areaUnit;

            if ($areaUnit > 0) {
                $dimensionCharge = ($totalDimensionArea / $areaUnit) * $amount;
                $shippingCost += $dimensionCharge;
            }
        }

        return round($shippingCost, 2);
    }

    /**
     * Convert weight to ounces
     */
    private function convertToOunces(float $weight, string $unit): float
    {
        return match ($unit) {
            'lb' => $weight * 16,
            'kg' => $weight * 35.274,
            'g' => $weight * 0.035274,
            default => $weight,
        };
    }



}