<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Services;

use InvalidArgumentException;
use Simp\Pindrop\Settings\Settings;

final class AdjustmentConfigurationService
{
    private const SETTINGS_KEY = 'stockflow.adjustments';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function getRules(string $kind, bool $enabledOnly = false): array
    {
        $this->assertKind($kind);
        $configuration = $this->settings->getSetting(self::SETTINGS_KEY)?->getValue() ?? [];
        $rules = $configuration[$kind] ?? [];
        if (!is_array($rules)) {
            return [];
        }

        $rules = array_values(array_filter(
            $rules,
            fn (mixed $rule): bool => is_array($rule)
                && (!$enabledOnly || (($rule['status'] ?? '') === 'active'
                    && $this->isWithinDateRange($rule)))
        ));
        foreach ($rules as &$rule) {
            if (!isset($rule['apply_to'])) {
                $rule['apply_to'] = 'legacy';
            }
        }
        unset($rule);
        return $rules;
    }

    public function getRule(string $kind, string $id): ?array
    {
        foreach ($this->getRules($kind) as $rule) {
            if (($rule['id'] ?? '') === $id) {
                return $rule;
            }
        }
        return null;
    }

    public function saveRule(string $kind, array $data, ?string $id = null, array $productIds = []): string
    {
        $this->assertKind($kind);
        $name = $this->requiredString($data['name'] ?? '', 'Name');
        $description = $this->requiredString($data['description'] ?? '', 'Description');
        $calculationType = $data['calculation_type'] ?? '';
        if (!is_string($calculationType) || !in_array($calculationType, ['fixed', 'percentage'], true)) {
            throw new InvalidArgumentException('Select a valid adjustment calculation type.');
        }
        $amountInput = $this->requiredString($data['amount'] ?? '', 'Amount');
        if (!is_numeric($amountInput) || !is_finite((float) $amountInput) || (float) $amountInput < 0) {
            throw new InvalidArgumentException('Amount must be a non-negative number.');
        }
        $amount = (float) $amountInput;
        if ($calculationType === 'percentage' && $amount > 100) {
            throw new InvalidArgumentException('Percentage must not exceed 100.');
        }
        $applyTo = $data['apply_to'] ?? '';
        if (!is_string($applyTo) || !in_array($applyTo, ['product', 'all_products', 'order_subtotal'], true)) {
            throw new InvalidArgumentException('Select a valid tax or discount application scope.');
        }
        $productId = null;
        if ($applyTo === 'product') {
            $productIdInput = $data['product_id'] ?? '';
            $productId = filter_var($productIdInput, FILTER_VALIDATE_INT);
            if ($productId === false || !in_array($productId, $productIds, true)) {
                throw new InvalidArgumentException('Select a valid product for this rule.');
            }
        }
        $startsAt = $kind === 'discounts' ? $this->optionalDate($data['starts_at'] ?? '', 'Start date') : null;
        $endsAt = $kind === 'discounts' ? $this->optionalDate($data['ends_at'] ?? '', 'End date') : null;
        if ($startsAt !== null && $endsAt !== null && $startsAt > $endsAt) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }
        $minimumOrderTotal = $kind === 'discounts'
            ? $this->optionalThreshold($data['minimum_order_total'] ?? '', 'Minimum order total')
            : null;
        $maximumOrderTotal = $kind === 'discounts'
            ? $this->optionalThreshold($data['maximum_order_total'] ?? '', 'Maximum order total')
            : null;
        if ($minimumOrderTotal !== null && $maximumOrderTotal !== null && $minimumOrderTotal > $maximumOrderTotal) {
            throw new InvalidArgumentException('Maximum order total must be greater than or equal to minimum order total.');
        }
        $minimumTotalItems = $kind === 'discounts'
            ? $this->optionalCount($data['minimum_total_items'] ?? '', 'Minimum total items')
            : null;
        $maximumTotalItems = $kind === 'discounts'
            ? $this->optionalCount($data['maximum_total_items'] ?? '', 'Maximum total items')
            : null;
        if ($minimumTotalItems !== null && $maximumTotalItems !== null && $minimumTotalItems > $maximumTotalItems) {
            throw new InvalidArgumentException('Maximum total items must be greater than or equal to minimum total items.');
        }
        $minimumDistinctProducts = $kind === 'discounts'
            ? $this->optionalCount($data['minimum_distinct_products'] ?? '', 'Minimum distinct products')
            : null;
        $maximumDistinctProducts = $kind === 'discounts'
            ? $this->optionalCount($data['maximum_distinct_products'] ?? '', 'Maximum distinct products')
            : null;
        if (
            $minimumDistinctProducts !== null
            && $maximumDistinctProducts !== null
            && $minimumDistinctProducts > $maximumDistinctProducts
        ) {
            throw new InvalidArgumentException('Maximum distinct products must be greater than or equal to minimum distinct products.');
        }
        $status = $data['status'] ?? '';
        if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Select a valid adjustment status.');
        }

        $configuration = $this->settings->getSetting(self::SETTINGS_KEY)?->getValue() ?? [];
        if (!is_array($configuration)) {
            $configuration = [];
        }
        $rules = $configuration[$kind] ?? [];
        if (!is_array($rules)) {
            $rules = [];
        }
        $id ??= bin2hex(random_bytes(8));
        $savedRule = [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'calculation_type' => $calculationType,
            'amount' => $amount,
            'status' => $status,
            'apply_to' => $applyTo,
            'product_id' => $productId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'minimum_order_total' => $minimumOrderTotal,
            'maximum_order_total' => $maximumOrderTotal,
            'minimum_total_items' => $minimumTotalItems,
            'maximum_total_items' => $maximumTotalItems,
            'minimum_distinct_products' => $minimumDistinctProducts,
            'maximum_distinct_products' => $maximumDistinctProducts,
        ];
        $updated = false;
        foreach ($rules as &$rule) {
            if (($rule['id'] ?? '') === $id) {
                $rule = $savedRule;
                $updated = true;
                break;
            }
        }
        unset($rule);
        if (!$updated) {
            if ($id !== null && isset($data['id']) && $data['id'] !== '') {
                throw new InvalidArgumentException('Adjustment rule was not found.');
            }
            $rules[] = $savedRule;
        }
        $configuration[$kind] = $rules;
        $this->settings->createSetting(self::SETTINGS_KEY, $configuration);
        return $id;
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, ['taxes', 'discounts'], true)) {
            throw new InvalidArgumentException('Adjustment group must be taxes or discounts.');
        }
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return trim($value);
    }

    private function optionalDate(mixed $value, string $label): ?string
    {
        $input = $this->scalarString($value, $label);
        if ($input === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if ($date === false || $date->format('Y-m-d') !== $input) {
            throw new InvalidArgumentException($label . ' must be a valid date.');
        }
        return $input;
    }

    private function optionalThreshold(mixed $value, string $label): ?float
    {
        $input = $this->scalarString($value, $label);
        if ($input === '') {
            return null;
        }
        if (!is_numeric($input) || !is_finite((float) $input) || (float) $input < 0) {
            throw new InvalidArgumentException($label . ' must be a valid non-negative amount.');
        }
        return (float) $input;
    }

    private function optionalCount(mixed $value, string $label): ?int
    {
        $input = $this->scalarString($value, $label);
        if ($input === '') {
            return null;
        }
        $count = filter_var($input, FILTER_VALIDATE_INT);
        if ($count === false || $count < 0) {
            throw new InvalidArgumentException($label . ' must be a non-negative whole number.');
        }
        return $count;
    }

    private function scalarString(mixed $value, string $label): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException($label . ' must be a single value.');
        }
        return trim((string) ($value ?? ''));
    }

    private function isWithinDateRange(array $rule): bool
    {
        $today = date('Y-m-d');
        return (!isset($rule['starts_at']) || $rule['starts_at'] === null || $rule['starts_at'] === '' || $rule['starts_at'] <= $today)
            && (!isset($rule['ends_at']) || $rule['ends_at'] === null || $rule['ends_at'] === '' || $rule['ends_at'] >= $today);
    }
}
