<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Plugin;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\commerce_store\src\Services\Currency;

class Price
{
    protected string $amount;
    protected string $currency;
    protected Currency $currencyService;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function __construct(string $amount, string $currency)
    {
        $this->amount = $amount;
        $this->currency = $currency;
        $this->currencyService = \getAppContainer()->get('commerce_store.currencies');
    }

    /**
     * Get the raw amount as float
     */
    public function getAmount(): float
    {
        return (float) $this->amount;
    }

    /**
     * Get the currency code
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Format price with currency symbol
     */
    public function format(): string
    {
        return $this->currencyService->format($this->getAmount(), $this->currency);
    }

    /**
     * Format price with currency code
     */
    public function formatWithCode(): string
    {
        return $this->currencyService->formatWithCode($this->getAmount(), $this->currency);
    }

    /**
     * Format price according to display rules
     */
    public function formatByDisplayRules(): string
    {
        return $this->currencyService->formatByDisplayRules($this->getAmount(), $this->currency);
    }

    /**
     * Convert to different currency
     */
    public function convert(string $toCurrency, ?float $rate = null): self
    {
        $convertedAmount = $this->currencyService->convert(
            $this->getAmount(),
            $this->currency,
            $toCurrency,
            $rate
        );
        
        return new self((string) $convertedAmount, $toCurrency);
    }

    /**
     * Add another price (must be same currency)
     */
    public function add(self $price): self
    {
        if ($price->getCurrency() !== $this->currency) {
            throw new \InvalidArgumentException('Cannot add prices with different currencies');
        }
        
        $newAmount = $this->getAmount() + $price->getAmount();
        return new self((string) $newAmount, $this->currency);
    }

    /**
     * Subtract another price (must be the same currency)
     */
    public function subtract(self $price): self
    {
        if ($price->getCurrency() !== $this->currency) {
            throw new \InvalidArgumentException('Cannot subtract prices with different currencies');
        }
        
        $newAmount = $this->getAmount() - $price->getAmount();
        return new self((string) $newAmount, $this->currency);
    }

    /**
     * Multiply by a factor
     */
    public function multiply(float $factor): self
    {
        $newAmount = $this->getAmount() * $factor;
        return new self((string) $newAmount, $this->currency);
    }

    /**
     * Divide by a factor
     */
    public function divide(float $factor): self
    {
        if ($factor == 0) {
            throw new \InvalidArgumentException('Cannot divide by zero');
        }
        
        $newAmount = $this->getAmount() / $factor;
        return new self((string) $newAmount, $this->currency);
    }

    /**
     * Calculate the percentage of this price
     */
    public function percentage(float $percent): self
    {
        return $this->multiply($percent / 100);
    }

    /**
     * Apply discount percentage
     */
    public function discount(float $percent): self
    {
        $discountAmount = $this->percentage($percent);
        return $this->subtract($discountAmount);
    }

    /**
     * Apply tax percentage
     */
    public function tax(float $percent): self
    {
        $taxAmount = $this->percentage($percent);
        return $this->add($taxAmount);
    }

    /**
     * Calculate total with tax
     */
    public function totalWithTax(float $taxPercent): self
    {
        $taxAmount = $this->currencyService->calculateTax(
            $this->getAmount(),
            $this->currency,
            $taxPercent
        );
        
        $total = $this->getAmount() + $taxAmount;
        return new self((string) $total, $this->currency);
    }

    /**
     * Round to currency precision
     */
    public function round(): self
    {
        $roundedAmount = $this->currencyService->round($this->getAmount(), $this->currency);
        return new self((string) $roundedAmount, $this->currency);
    }

    /**
     * Get minor unit (cents) value
     */
    public function getMinorUnit(): int
    {
        return $this->currencyService->getMinorUnit($this->getAmount(), $this->currency);
    }

    /**
     * Create from minor unit
     */
    public static function fromMinorUnit(int $minorAmount, string $currency): self
    {
        $currencyService = \getAppContainer()->get('commerce_store.currencies');
        $amount = $currencyService->fromMinorUnit($minorAmount, $currency);
        return new self((string) $amount, $currency);
    }

    /**
     * Compare with another price
     */
    public function compareTo(self $price): int
    {
        if ($price->getCurrency() !== $this->currency) {
            throw new \InvalidArgumentException('Cannot compare prices with different currencies');
        }
        
        return $this->getAmount() <=> $price->getAmount();
    }

    /**
     * Check if equal to another price
     */
    public function equals(self $price): bool
    {
        return $this->compareTo($price) === 0;
    }

    /**
     * Check if greater than another price
     */
    public function greaterThan(self $price): bool
    {
        return $this->compareTo($price) > 0;
    }

    /**
     * Check if less than another price
     */
    public function lessThan(self $price): bool
    {
        return $this->compareTo($price) < 0;
    }

    /**
     * Check if greater than or equal to another price
     */
    public function greaterThanOrEqual(self $price): bool
    {
        return $this->compareTo($price) >= 0;
    }

    /**
     * Check if less than or equal to another price
     */
    public function lessThanOrEqual(self $price): bool
    {
        return $this->compareTo($price) <= 0;
    }

    /**
     * Check if price is zero
     */
    public function isZero(): bool
    {
        return $this->getAmount() == 0;
    }

    /**
     * Check if price is positive
     */
    public function isPositive(): bool
    {
        return $this->getAmount() > 0;
    }

    /**
     * Check if price is negative
     */
    public function isNegative(): bool
    {
        return $this->getAmount() < 0;
    }

    /**
     * Get absolute value
     */
    public function absolute(): self
    {
        $absoluteAmount = abs($this->getAmount());
        return new self((string) $absoluteAmount, $this->currency);
    }

    /**
     * Get minimum of multiple prices
     */
    public static function min(self ...$prices): self
    {
        if (empty($prices)) {
            throw new \InvalidArgumentException('At least one price is required');
        }
        
        $minPrice = $prices[0];
        foreach ($prices as $price) {
            if ($price->lessThan($minPrice)) {
                $minPrice = $price;
            }
        }
        
        return $minPrice;
    }

    /**
     * Get maximum of multiple prices
     */
    public static function max(self ...$prices): self
    {
        if (empty($prices)) {
            throw new \InvalidArgumentException('At least one price is required');
        }
        
        $maxPrice = $prices[0];
        foreach ($prices as $price) {
            if ($price->greaterThan($maxPrice)) {
                $maxPrice = $price;
            }
        }
        
        return $maxPrice;
    }

    /**
     * Sum multiple prices (must be same currency)
     */
    public static function sum(self ...$prices): self
    {
        if (empty($prices)) {
            throw new \InvalidArgumentException('At least one price is required');
        }
        
        $total = $prices[0];
        for ($i = 1; $i < count($prices); $i++) {
            $total = $total->add($prices[$i]);
        }
        
        return $total;
    }

    /**
     * Calculate average of multiple prices (must be same currency)
     */
    public static function average(self ...$prices): self
    {
        if (empty($prices)) {
            throw new \InvalidArgumentException('At least one price is required');
        }
        
        $sum = self::sum(...$prices);
        return $sum->divide(count($prices));
    }

    /**
     * Get currency symbol
     */
    public function getSymbol(): string
    {
        return $this->currencyService->getSymbol($this->currency) ?? '';
    }

    /**
     * Get currency name
     */
    public function getCurrencyName(): string
    {
        return $this->currencyService->getName($this->currency) ?? '';
    }

    /**
     * Get number of decimal places
     */
    public function getDecimals(): int
    {
        return $this->currencyService->getDecimals($this->currency);
    }

    /**
     * Check if currency uses decimals
     */
    public function usesDecimals(): bool
    {
        return $this->currencyService->usesDecimals($this->currency);
    }

    /**
     * Validate price amount
     */
    public function isValid(): bool
    {
        return $this->currencyService->validateAmount($this->getAmount(), $this->currency);
    }

    /**
     * Convert to string representation
     */
    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->getAmount(),
            'currency' => $this->currency,
            'formatted' => $this->format(),
            'formatted_with_code' => $this->formatWithCode(),
            'symbol' => $this->getSymbol(),
            'minor_unit' => $this->getMinorUnit()
        ];
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['amount'], $data['currency'])) {
            throw new \InvalidArgumentException('Array must contain amount and currency');
        }
        
        return new self((string) $data['amount'], $data['currency']);
    }

    /**
     * Clone with different amount
     */
    public function withAmount(float $amount): self
    {
        return new self((string) $amount, $this->currency);
    }

    /**
     * Clone with different currency
     */
    public function withCurrency(string $currency): self
    {
        return new self($this->amount, $currency);
    }

    /**
     * Get price as JSON
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
}