<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Plugin;

use DI\DependencyException;
use DI\NotFoundException;

class Adjustment
{
    protected Price $amount;
    protected string $label;
    protected string $type;
    protected string $source;
    protected ?string $description;
    protected ?int $priority;
    protected bool $inclusive;
    protected array $metadata;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function __construct(array $adjustment)
    {
        $required = ['amount', 'label', 'type', 'source'];
        foreach ($required as $field) {
            if (!isset($adjustment[$field])) {
                throw new \InvalidArgumentException("Adjustment missing required field: {$field}");
            }
        }
        
        $this->label = $adjustment['label'];
        $this->type = $adjustment['type'];
        $this->source = $adjustment['source'];
        $this->description = $adjustment['description'] ?? null;
        $this->priority = (int) ($adjustment['priority'] ?? 0);
        $this->inclusive = (bool) ($adjustment['inclusive'] ?? false);
        $this->metadata = $adjustment['metadata'] ?? [];

        if (is_array($adjustment['amount'])) {
            $this->amount = Price::fromArray($adjustment['amount']);
        }
        elseif ($adjustment['amount'] instanceof Price) {
            $this->amount = $adjustment['amount'];
        }
        elseif (is_numeric($adjustment['amount'])) {
           $this->amount = Price::fromArray([
               'amount' => $adjustment['amount'],
               'currency' => \getAppContainer()->get('commerce_store.currencies')->getDefaultCurrency()
           ]);
        }
        else {
            throw new \InvalidArgumentException("Adjustment amount must be numeric or Price object");
        }
    }

    /**
     * Get the adjustment amount
     */
    public function getAmount(): Price
    {
        return $this->amount;
    }

    /**
     * Get the adjustment label
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Get the adjustment type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the adjustment source
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Get the adjustment description
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get the adjustment priority
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Check if adjustment is inclusive (already included in base price)
     */
    public function isInclusive(): bool
    {
        return $this->inclusive;
    }

    /**
     * Get metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if this is a positive adjustment (adds to total)
     */
    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    /**
     * Check if this is a negative adjustment (subtracts from total)
     */
    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    /**
     * Check if this is a zero adjustment
     */
    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    /**
     * Check if adjustment is of a specific type
     */
    public function isType(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Check if adjustment is from a specific source
     */
    public function isFromSource(string $source): bool
    {
        return $this->source === $source;
    }

    /**
     * Apply adjustment to a base price
     */
    public function applyTo(Price $basePrice): Price
    {
        if ($this->amount->getCurrency() !== $basePrice->getCurrency()) {
            throw new \InvalidArgumentException('Cannot apply adjustment with different currency');
        }

        return $basePrice->add($this->amount);
    }

    /**
     * Apply adjustment to a base price (considering inclusive flag)
     */
    public function applyToWithInclusive(Price $basePrice): Price
    {
        if ($this->amount->getCurrency() !== $basePrice->getCurrency()) {
            throw new \InvalidArgumentException('Cannot apply adjustment with different currency');
        }

        // If adjustment is inclusive, don't add it to the price
        return $this->inclusive ? $basePrice : $basePrice->add($this->amount);
    }

    /**
     * Create a positive adjustment
     */
    public static function positive(float $amount, string $currency, string $label, string $source, array $options = []): self
    {
        return new self([
            'amount' => $amount,
            'currency' => $currency,
            'label' => $label,
            'type' => $options['type'] ?? 'custom',
            'source' => $source,
            'description' => $options['description'] ?? null,
            'priority' => $options['priority'] ?? 0,
            'inclusive' => $options['inclusive'] ?? false,
            'metadata' => $options['metadata'] ?? []
        ]);
    }

    /**
     * Create a negative adjustment
     */
    public static function negative(float $amount, string $currency, string $label, string $source, array $options = []): self
    {
        return new self([
            'amount' => abs($amount) * -1,
            'currency' => $currency,
            'label' => $label,
            'type' => $options['type'] ?? 'custom',
            'source' => $source,
            'description' => $options['description'] ?? null,
            'priority' => $options['priority'] ?? 0,
            'inclusive' => $options['inclusive'] ?? false,
            'metadata' => $options['metadata'] ?? []
        ]);
    }

    /**
     * Create a tax adjustment
     */
    public static function tax(float $amount, string $currency, string $label, string $source, array $options = []): self
    {
        return new self([
            'amount' => $amount,
            'currency' => $currency,
            'label' => $label,
            'type' => 'tax',
            'source' => $source,
            'description' => $options['description'] ?? null,
            'priority' => $options['priority'] ?? 100,
            'inclusive' => $options['inclusive'] ?? false,
            'metadata' => $options['metadata'] ?? []
        ]);
    }

    /**
     * Create a discount adjustment
     */
    public static function discount(float $amount, string $currency, string $label, string $source, array $options = []): self
    {
        return new self([
            'amount' => abs($amount) * -1,
            'currency' => $currency,
            'label' => $label,
            'type' => 'discount',
            'source' => $source,
            'description' => $options['description'] ?? null,
            'priority' => $options['priority'] ?? 50,
            'inclusive' => $options['inclusive'] ?? false,
            'metadata' => $options['metadata'] ?? []
        ]);
    }

    /**
     * Create a shipping adjustment
     */
    public static function shipping(float $amount, string $currency, string $label, string $source, array $options = []): self
    {
        return new self([
            'amount' => $amount,
            'currency' => $currency,
            'label' => $label,
            'type' => 'shipping',
            'source' => $source,
            'description' => $options['description'] ?? null,
            'priority' => $options['priority'] ?? 75,
            'inclusive' => $options['inclusive'] ?? false,
            'metadata' => $options['metadata'] ?? []
        ]);
    }

    /**
     * Create a fee adjustment
     */
    public static function fee(float $amount, string $currency, string $label, string $source, array $options = []): self
    {
        return new self([
            'amount' => $amount,
            'currency' => $currency,
            'label' => $label,
            'type' => 'fee',
            'source' => $source,
            'description' => $options['description'] ?? null,
            'priority' => $options['priority'] ?? 25,
            'inclusive' => $options['inclusive'] ?? false,
            'metadata' => $options['metadata'] ?? []
        ]);
    }

    /**
     * Clone with different amount
     */
    public function withAmount(Price $amount): self
    {
        return new self([
            'amount' => $amount,
            'label' => $this->label,
            'type' => $this->type,
            'source' => $this->source,
            'description' => $this->description,
            'priority' => $this->priority,
            'inclusive' => $this->inclusive,
            'metadata' => $this->metadata
        ]);
    }

    /**
     * Clone with different label
     */
    public function withLabel(string $label): self
    {
        return new self([
            'amount' => $this->amount,
            'label' => $label,
            'type' => $this->type,
            'source' => $this->source,
            'description' => $this->description,
            'priority' => $this->priority,
            'inclusive' => $this->inclusive,
            'metadata' => $this->metadata
        ]);
    }

    /**
     * Clone with different priority
     */
    public function withPriority(int $priority): self
    {
        return new self([
            'amount' => $this->amount,
            'label' => $this->label,
            'type' => $this->type,
            'source' => $this->source,
            'description' => $this->description,
            'priority' => $priority,
            'inclusive' => $this->inclusive,
            'metadata' => $this->metadata
        ]);
    }

    /**
     * Clone as inclusive
     */
    public function asInclusive(): self
    {
        return new self([
            'amount' => $this->amount,
            'label' => $this->label,
            'type' => $this->type,
            'source' => $this->source,
            'description' => $this->description,
            'priority' => $this->priority,
            'inclusive' => true,
            'metadata' => $this->metadata
        ]);
    }

    /**
     * Clone as exclusive
     */
    public function asExclusive(): self
    {
        return new self([
            'amount' => $this->amount,
            'label' => $this->label,
            'type' => $this->type,
            'source' => $this->source,
            'description' => $this->description,
            'priority' => $this->priority,
            'inclusive' => false,
            'metadata' => $this->metadata
        ]);
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount->toArray(),
            'label' => $this->label,
            'type' => $this->type,
            'source' => $this->source,
            'description' => $this->description,
            'priority' => $this->priority,
            'inclusive' => $this->inclusive,
            'metadata' => $this->metadata,
            'is_positive' => $this->isPositive(),
            'is_negative' => $this->isNegative(),
            'is_zero' => $this->isZero()
        ];
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Get adjustment as JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Create from JSON
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }
        
        return self::fromArray($data);
    }

    /**
     * Convert to string representation
     */
    public function __toString(): string
    {
        $sign = $this->isPositive() ? '+' : ($this->isNegative() ? '-' : '');
        return "{$sign}{$this->amount->format()} ({$this->label})";
    }

    /**
     * Compare adjustments by priority
     */
    public static function compareByPriority(self $a, self $b): int
    {
        return $a->getPriority() <=> $b->getPriority();
    }

    /**
     * Filter adjustments by type
     */
    public static function filterByType(array $adjustments, string $type): array
    {
        return array_filter($adjustments, function(self $adjustment) use ($type) {
            return $adjustment->isType($type);
        });
    }

    /**
     * Filter adjustments by source
     */
    public static function filterBySource(array $adjustments, string $source): array
    {
        return array_filter($adjustments, function(self $adjustment) use ($source) {
            return $adjustment->isFromSource($source);
        });
    }

    /**
     * Filter positive adjustments
     */
    public static function filterPositive(array $adjustments): array
    {
        return array_filter($adjustments, function(self $adjustment) {
            return $adjustment->isPositive();
        });
    }

    /**
     * Filter negative adjustments
     */
    public static function filterNegative(array $adjustments): array
    {
        return array_filter($adjustments, function(self $adjustment) {
            return $adjustment->isNegative();
        });
    }

    /**
     * Filter inclusive adjustments
     */
    public static function filterInclusive(array $adjustments): array
    {
        return array_filter($adjustments, function(self $adjustment) {
            return $adjustment->isInclusive();
        });
    }

    /**
     * Filter exclusive adjustments
     */
    public static function filterExclusive(array $adjustments): array
    {
        return array_filter($adjustments, function(self $adjustment) {
            return !$adjustment->isInclusive();
        });
    }

    /**
     * Sort adjustments by priority
     */
    public static function sortByPriority(array $adjustments): array
    {
        usort($adjustments, [self::class, 'compareByPriority']);
        return $adjustments;
    }

    /**
     * Calculate total adjustment amount
     */
    public static function sum(array $adjustments): Price
    {
        if (empty($adjustments)) {
            throw new \InvalidArgumentException('At least one adjustment is required');
        }

        $total = $adjustments[0]->getAmount();
        for ($i = 1; $i < count($adjustments); $i++) {
            $total = $total->add($adjustments[$i]->getAmount());
        }

        return $total;
    }

    /**
     * Calculate total positive adjustments
     */
    public static function sumPositive(array $adjustments): Price
    {
        $positive = self::filterPositive($adjustments);
        return empty($positive) ? new Price('0', $adjustments[0]->getAmount()->getCurrency()) : self::sum($positive);
    }

    /**
     * Calculate total negative adjustments
     */
    public static function sumNegative(array $adjustments): Price
    {
        $negative = self::filterNegative($adjustments);
        return empty($negative) ? new Price('0', $adjustments[0]->getAmount()->getCurrency()) : self::sum($negative);
    }

    /**
     * Get adjustment types
     */
    public static function getTypes(): array
    {
        return [
            'tax' => 'Tax adjustment',
            'discount' => 'Discount adjustment',
            'shipping' => 'Shipping adjustment',
            'fee' => 'Fee adjustment',
            'custom' => 'Custom adjustment'
        ];
    }

    /**
     * Validate adjustment data
     */
    public function isValid(): bool
    {
        $validTypes = array_keys(self::getTypes());
        
        return !empty($this->label) 
            && in_array($this->type, $validTypes)
            && !empty($this->source)
            && $this->amount->isValid();
    }
}