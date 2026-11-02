<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Services;

use InvalidArgumentException;
use Simp\Pindrop\Settings\Settings;
use Simp\Price\lib\Currency;
use Simp\Price\lib\Price;

final class ShippingMethodService
{
    private const SETTINGS_KEY = 'stockflow.shipping_methods';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function getMethods(): array
    {
        $methods = $this->settings->getSetting(self::SETTINGS_KEY)?->getValue() ?? [];
        return is_array($methods) ? array_values($methods) : [];
    }

    public function getMethod(string $id): ?array
    {
        foreach ($this->getMethods() as $method) {
            if (($method['id'] ?? '') === $id) {
                return $method;
            }
        }
        return null;
    }

    public function saveMethod(array $data, ?string $id = null): string
    {
        $currency = strtoupper((string) (
            $this->settings->getSetting('stockflow.settings')?->get('currency')
            ?? getenv('STOCKFLOW_CURRENCY')
            ?: 'USD'
        ));
        new Currency($currency);
        $name = $this->requiredString($data['name'] ?? '', 'Shipping method name');
        $type = $data['type'] ?? 'flat_rate';
        if (!is_string($type) || !in_array($type, ['flat_rate', 'price_based', 'weight_based'], true)) {
            throw new InvalidArgumentException('Select a valid shipping calculation type.');
        }
        $rate = $this->price($data['flat_rate'] ?? '', $currency, 'Flat rate');
        if ($rate->isLessThan(0.0)) {
            throw new InvalidArgumentException('Flat rate cannot be negative.');
        }
        $ratePerKgInput = $this->scalarString($data['rate_per_kg'] ?? '', 'Rate per kilogram');
        $ratePerKg = null;
        if ($type === 'weight_based') {
            if ($ratePerKgInput === '') {
                throw new InvalidArgumentException('Rate per kilogram is required for weight-based shipping.');
            }
            $ratePerKg = $this->price($ratePerKgInput, $currency, 'Rate per kilogram');
            if ($ratePerKg->isLessThan(0.0)) {
                throw new InvalidArgumentException('Rate per kilogram cannot be negative.');
            }
        }
        $rawThreshold = $data['free_threshold'] ?? '';
        if (!is_scalar($rawThreshold) && $rawThreshold !== null) {
            throw new InvalidArgumentException('Free-shipping threshold must be a single value.');
        }
        $thresholdInput = trim((string) ($rawThreshold ?? ''));
        $threshold = null;
        if ($thresholdInput !== '') {
            $threshold = $this->price($thresholdInput, $currency, 'Free-shipping threshold');
            if ($threshold->isLessThan(0.0)) {
                throw new InvalidArgumentException('Free-shipping threshold cannot be negative.');
            }
        }
        $minimumOrderTotal = $this->optionalPrice($data['minimum_order_total'] ?? '', $currency, 'Minimum order total');
        $maximumOrderTotal = $this->optionalPrice($data['maximum_order_total'] ?? '', $currency, 'Maximum order total');
        if ($minimumOrderTotal !== null && $maximumOrderTotal !== null
            && !$minimumOrderTotal->isLessThan($maximumOrderTotal)) {
            throw new InvalidArgumentException('Maximum order total must be greater than minimum order total.');
        }
        if ($type === 'price_based' && $minimumOrderTotal === null && $maximumOrderTotal === null) {
            throw new InvalidArgumentException('Set at least one order-total boundary for price-based shipping.');
        }
        $minimumWeight = $this->optionalInteger($data['minimum_weight_grams'] ?? '', 'Minimum shipment weight');
        $maximumWeight = $this->optionalInteger($data['maximum_weight_grams'] ?? '', 'Maximum shipment weight');
        if ($minimumWeight !== null && $maximumWeight !== null && $maximumWeight <= $minimumWeight) {
            throw new InvalidArgumentException('Maximum shipment weight must be greater than minimum shipment weight.');
        }
        $status = $data['status'] ?? '';
        if (!is_string($status)) {
            throw new InvalidArgumentException('Select a valid shipping method status.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Select a valid shipping method status.');
        }
        $methods = $this->getMethods();
        $isNew = $id === null;
        $id ??= bin2hex(random_bytes(8));
        $found = false;
        foreach ($methods as &$method) {
            if (($method['id'] ?? '') === $id) {
                $method = [
                    'id' => $id,
                    'name' => $name,
                    'type' => $type,
                    'flat_rate' => (string) $rate,
                    'rate_per_kg' => $ratePerKg === null ? null : (string) $ratePerKg,
                    'free_threshold' => $threshold === null ? null : (string) $threshold,
                    'minimum_order_total' => $minimumOrderTotal === null ? null : (string) $minimumOrderTotal,
                    'maximum_order_total' => $maximumOrderTotal === null ? null : (string) $maximumOrderTotal,
                    'minimum_weight_grams' => $minimumWeight,
                    'maximum_weight_grams' => $maximumWeight,
                    'status' => $status,
                ];
                $found = true;
                break;
            }
        }
        unset($method);
        if (!$found) {
            if (!$isNew) {
                throw new InvalidArgumentException('Shipping method was not found.');
            }
            $methods[] = [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'flat_rate' => (string) $rate,
                'rate_per_kg' => $ratePerKg === null ? null : (string) $ratePerKg,
                'free_threshold' => $threshold === null ? null : (string) $threshold,
                'minimum_order_total' => $minimumOrderTotal === null ? null : (string) $minimumOrderTotal,
                'maximum_order_total' => $maximumOrderTotal === null ? null : (string) $maximumOrderTotal,
                'minimum_weight_grams' => $minimumWeight,
                'maximum_weight_grams' => $maximumWeight,
                'status' => $status,
            ];
        }
        $this->settings->createSetting(self::SETTINGS_KEY, $methods);
        return $id;
    }

    public function deleteMethod(string $id): void
    {
        $methods = $this->getMethods();
        $filtered = array_values(array_filter($methods, static fn (array $method): bool => ($method['id'] ?? '') !== $id));
        if (count($filtered) === count($methods)) {
            throw new InvalidArgumentException('Shipping method was not found.');
        }
        $this->settings->createSetting(self::SETTINGS_KEY, $filtered);
    }

    public function calculate(string $id, Price $subtotal, int $totalWeightGrams = 0): Price
    {
        $method = $this->getMethod($id);
        if ($method === null || ($method['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('Selected shipping method is unavailable.');
        }
        $currency = strtoupper((string) (
            $this->settings->getSetting('stockflow.settings')?->get('currency')
            ?? getenv('STOCKFLOW_CURRENCY')
            ?: 'USD'
        ));
        if ($subtotal->getCurrency()->getCode() !== $currency || !is_finite($subtotal->getAmount())) {
            throw new InvalidArgumentException('Shipping subtotal currency does not match the store currency.');
        }
        $minimumOrderTotal = $method['minimum_order_total'] ?? null;
        $maximumOrderTotal = $method['maximum_order_total'] ?? null;
        if ($minimumOrderTotal !== null && $subtotal->isLessThan(new Price((float) $minimumOrderTotal, $currency))) {
            throw new InvalidArgumentException('Order total does not meet the minimum for this shipping method.');
        }
        if ($maximumOrderTotal !== null && $subtotal->isGreaterThan(new Price((float) $maximumOrderTotal, $currency))) {
            throw new InvalidArgumentException('Order total exceeds the maximum for this shipping method.');
        }
        $charge = new Price((float) $method['flat_rate'], $currency);
        if (($method['type'] ?? 'flat_rate') === 'weight_based') {
            if ($totalWeightGrams <= 0) {
                throw new InvalidArgumentException('A positive total shipment weight is required for weight-based shipping.');
            }
            $minimumWeight = $method['minimum_weight_grams'] ?? null;
            $maximumWeight = $method['maximum_weight_grams'] ?? null;
            if ($minimumWeight !== null && $totalWeightGrams < (int) $minimumWeight) {
                throw new InvalidArgumentException('Shipment weight is below this method minimum.');
            }
            if ($maximumWeight !== null && $totalWeightGrams > (int) $maximumWeight) {
                throw new InvalidArgumentException('Shipment weight exceeds this method maximum.');
            }
            $kilograms = (new Price((float) $totalWeightGrams, $currency))->divide(1000);
            $weightCharge = (new Price((float) $method['rate_per_kg'], $currency))->multiply($kilograms);
            $charge->add($weightCharge);
        }
        if (!empty($method['free_threshold'])) {
            $threshold = new Price((float) $method['free_threshold'], $currency);
            if (!$subtotal->isLessThan($threshold)) {
                $charge = new Price(0.0, $currency);
            }
        }
        return $charge;
    }

    private function price(mixed $value, string $currency, string $label): Price
    {
        if (!is_scalar($value) || trim((string) $value) === '' || !is_numeric($value)) {
            throw new InvalidArgumentException($label . ' must be a valid amount.');
        }
        $amount = (float) $value;
        if (!is_finite($amount)) {
            throw new InvalidArgumentException($label . ' must be a finite amount.');
        }
        return new Price($amount, $currency);
    }

    private function optionalPrice(mixed $value, string $currency, string $label): ?Price
    {
        $input = $this->scalarString($value, $label);
        if ($input === '') {
            return null;
        }
        $price = $this->price($input, $currency, $label);
        if ($price->isLessThan(0.0)) {
            throw new InvalidArgumentException($label . ' cannot be negative.');
        }
        return $price;
    }

    private function optionalInteger(mixed $value, string $label): ?int
    {
        $input = $this->scalarString($value, $label);
        if ($input === '') {
            return null;
        }
        $integer = filter_var($input, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new InvalidArgumentException($label . ' must be a non-negative whole number.');
        }
        return $integer;
    }

    private function scalarString(mixed $value, string $label): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException($label . ' must be a single value.');
        }
        return trim((string) ($value ?? ''));
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return trim($value);
    }
}
