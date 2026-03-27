<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Services;

use Simp\Pindrop\Plugin\PluginManager;

class AdjustmentService
{
    protected array $adjustments;
    protected PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
        $adjustments = $this->pluginManager->getPluginsYamlContent('adjustment');
        $adjustments = array_values($adjustments);

        // sort by weight
        usort($adjustments, function($a, $b) {
            return ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
        });

        // then add to $this->adjustments no override
        foreach ($adjustments as $adjustment) {
            foreach ($adjustment as $k=>$adjust) {

                if (!isset($this->adjustments[$k])) {
                    $this->adjustments[$k] = $adjust;
                }

            }
        }
    }

    /**
     * Get all adjustments
     */
    public function getAdjustments(): array
    {
        return $this->adjustments;
    }

    /**
     * Get adjustment by name
     */
    public function getAdjustment(string $name): ?array
    {
        return array_find($this->adjustments, fn($adjustment) => $adjustment['type'] === $name);
    }

    /**
     * Apply adjustments to a price
     */
    public function applyAdjustments(float $basePrice, array $context = []): float
    {
        $total = $basePrice;

        foreach ($this->adjustments as $adjustment) {
            if ($this->shouldApplyAdjustment($adjustment, $context)) {
                $total = $this->calculateAdjustedPrice($total, $adjustment, $context);
            }
        }

        return $total;
    }

    /**
     * Check if adjustment should be applied based on conditions
     */
    protected function shouldApplyAdjustment(array $adjustment, array $context): bool
    {
        if (!isset($adjustment['conditions'])) {
            return true;
        }

        foreach ($adjustment['conditions'] as $condition) {
            if (!$this->evaluateCondition($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single condition
     */
    protected function evaluateCondition(array $condition, array $context): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;

        if (!$field) {
            return false;
        }

        $contextValue = $this->getNestedValue($context, $field);

        switch ($operator) {
            case 'equals':
                return $contextValue == $value;
            case 'not_equals':
                return $contextValue != $value;
            case 'greater_than':
                return $contextValue > $value;
            case 'less_than':
                return $contextValue < $value;
            case 'in':
                return in_array($contextValue, (array) $value);
            case 'not_in':
                return !in_array($contextValue, (array) $value);
            default:
                return false;
        }
    }

    /**
     * Get nested value from array using dot notation
     */
    protected function getNestedValue(array $array, string $key): mixed
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Calculate adjusted price based on adjustment configuration
     */
    protected function calculateAdjustedPrice(float $currentPrice, array $adjustment, array $context): float
    {
        $type = $adjustment['type'] ?? 'fixed';
        $amount = $adjustment['amount'] ?? 0;

        switch ($type) {
            case 'fixed':
                return $currentPrice + $amount;
            case 'percentage':
                return $currentPrice + ($currentPrice * ($amount / 100));
            case 'multiply':
                return $currentPrice * $amount;
            case 'divide':
                return $amount != 0 ? $currentPrice / $amount : $currentPrice;
            default:
                return $currentPrice;
        }
    }

    /**
     * Add new adjustment
     */
    public function addAdjustment(array $adjustment): void
    {
        $this->adjustments[] = $adjustment;
        
        // Re-sort by weight
        usort($this->adjustments, function($a, $b) {
            return ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
        });
    }

    /**
     * Remove adjustment by name
     */
    public function removeAdjustment(string $name): bool
    {
        foreach ($this->adjustments as $key => $adjustment) {
            if ($adjustment['name'] === $name) {
                unset($this->adjustments[$key]);
                $this->adjustments = array_values($this->adjustments);
                return true;
            }
        }
        return false;
    }

    /**
     * Reload adjustments from configuration
     */
    public function reloadAdjustments(): void
    {
        $adjustments = $this->pluginManager->getPluginsYamlContent('adjustment.yml');
        $adjustments = array_values($adjustments);

        // sort by weight
        usort($adjustments, function($a, $b) {
            return ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
        });

        $this->adjustments = $adjustments;
    }
}