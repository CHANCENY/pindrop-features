<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Services;

use Exception;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Symfony\Component\Yaml\Yaml;

class Currency
{
    protected string $repoFile;
    protected array  $currencies;

    protected DatabaseService $database;

    /**
     * @throws Exception
     */
    public function __construct(DatabaseService $database)
    {
        $this->database = $database;
        $this->repoFile = __DIR__ . "/repository/currencies.yml";
        if (!file_exists($this->repoFile)) {
            throw new Exception("Currency repository file not found: {$this->repoFile}");
        }

        $this->currencies = Yaml::parseFile($this->repoFile);

        if (!$this->currencies) {
            throw new Exception("Failed to parse currency repository file: {$this->repoFile}");
        }
    }

    public function getCurrencies(): array
    {
        return $this->currencies;
    }

    public function getSimplifiedCurrencies(): array
    {
        $currencies = [];
        foreach ($this->currencies as $code => $currency) {
            $currencies[$code] = "$code - {$currency['name']}";
        }

        return $currencies;
    }

    /**
     * Get currency information by code
     */
    public function getCurrency(string $code): ?array
    {
        return $this->currencies[strtoupper($code)] ?? null;
    }

    /**
     * Check if currency exists
     */
    public function currencyExists(string $code): bool
    {
        return isset($this->currencies[strtoupper($code)]);
    }

    /**
     * Get currency symbol
     */
    public function getSymbol(string $code): ?string
    {
        return $this->getCurrency($code)['symbol'] ?? null;
    }

    /**
     * Get currency name
     */
    public function getName(string $code): ?string
    {
        return $this->getCurrency($code)['name'] ?? null;
    }

    /**
     * Get number of decimal places for currency
     */
    public function getDecimals(string $code): int
    {
        return $this->getCurrency($code)['decimals'] ?? 2;
    }

    /**
     * Format amount with currency symbol
     */
    public function format(float $amount, string $code, bool $includeSymbol = true): string
    {
        $currency = $this->getCurrency($code);
        if (!$currency) {
            return number_format($amount, 2);
        }

        $decimals = $currency['decimals'] ?? 2;
        $formatted = number_format($amount, $decimals, '.', ',');
        
        if ($includeSymbol && isset($currency['symbol'])) {
            return $currency['symbol']['default']['display'] . $formatted;
        }

        return $formatted;
    }

    /**
     * Format amount with currency code (e.g., 100.00 USD)
     */
    public function formatWithCode(float $amount, string $code): string
    {
        $currency = $this->getCurrency($code);
        if (!$currency) {
            return number_format($amount, 2) . ' ' . $code;
        }

        $decimals = $currency['decimals'] ?? 2;
        $formatted = number_format($amount, $decimals, '.', ',');
        
        return $formatted . ' ' . $code;
    }

    /**
     * Convert amount between currencies
     */
    public function convert(float $amount, string $from, string $to, ?float $rate = null): float
    {
        if ($from === $to) {
            return $amount;
        }

        // If no rate provided, try to get from exchange rates
        if ($rate === null) {
            $rate = $this->getExchangeRate($from, $to);
        }

        if ($rate === null) {
            throw new Exception("Exchange rate not available for {$from} to {$to}");
        }

        return $amount * $rate;
    }

    /**
     * Get exchange rate between currencies
     */
    public function getExchangeRate(string $from, string $to): ?float
    {
        // This would typically integrate with an external API
        // For now, return null to indicate rate needs to be provided
        return null;
    }

    /**
     * Validate currency code
     */
    public function validateCurrencyCode(string $code): bool
    {
        return preg_match('/^[A-Z]{3}$/', strtoupper($code)) && $this->currencyExists($code);
    }

    /**
     * Get all currency codes
     */
    public function getCurrencyCodes(): array
    {
        return array_keys($this->currencies);
    }

    /**
     * Get active currencies (filter by status if available)
     */
    public function getActiveCurrencies(): array
    {
        return array_filter($this->currencies, function($currency) {
            return !isset($currency['active']) || $currency['active'] === true;
        });
    }

    /**
     * Get currencies by region/country
     */
    public function getCurrenciesByCountry(string $countryCode): array
    {
        return array_filter($this->currencies, function($currency) use ($countryCode) {
            return isset($currency['countries']) && in_array($countryCode, $currency['countries']);
        });
    }

    /**
     * Parse monetary string to float
     */
    public function parseAmount(string $amount, string $code): float
    {
        $currency = $this->getCurrency($code);
        if (!$currency) {
            return (float) preg_replace('/[^0-9.]/', '', $amount);
        }

        // Remove currency symbol and other non-numeric characters
        $symbol = $currency['symbol'] ?? '';
        $cleanAmount = str_replace($symbol, '', $amount);
        $cleanAmount = preg_replace('/[^0-9.-]/', '', $cleanAmount);
        
        return (float) $cleanAmount;
    }

    /**
     * Round amount to currency's decimal places
     */
    public function round(float $amount, string $code): float
    {
        $decimals = $this->getDecimals($code);
        return round($amount, $decimals);
    }

    /**
     * Calculate tax amount
     */
    public function calculateTax(float $amount, string $code, float $taxRate): float
    {
        $taxAmount = $amount * ($taxRate / 100);
        return $this->round($taxAmount, $code);
    }

    /**
     * Calculate total with tax
     */
    public function calculateTotalWithTax(float $amount, string $code, float $taxRate): float
    {
        $taxAmount = $this->calculateTax($amount, $code, $taxRate);
        return $this->round($amount + $taxAmount, $code);
    }

    /**
     * Apply discount to amount
     */
    public function applyDiscount(float $amount, string $code, float $discountPercent): float
    {
        $discountAmount = $amount * ($discountPercent / 100);
        return $this->round($amount - $discountAmount, $code);
    }

    /**
     * Get currency display format (symbol before/after)
     */
    public function getDisplayFormat(string $code): string
    {
        $currency = $this->getCurrency($code);
        return $currency['format'] ?? '{symbol}{amount}';
    }

    /**
     * Format amount according to currency's display format
     */
    public function formatByDisplayRules(float $amount, string $code): string
    {
        $currency = $this->getCurrency($code);
        if (!$currency) {
            return number_format($amount, 2);
        }

        $decimals = $currency['decimals'] ?? 2;
        $symbol = $currency['symbol'] ?? '';
        $formatted = number_format($amount, $decimals, '.', ',');
        $format = $currency['format'] ?? '{symbol}{amount}';

        return str_replace(['{symbol}', '{amount}'], [$symbol['default']['display'], $formatted], $format);
    }

    /**
     * Get currency precision for calculations
     */
    public function getPrecision(string $code): int
    {
        $currency = $this->getCurrency($code);
        return $currency['precision'] ?? $currency['decimals'] ?? 2;
    }

    /**
     * Check if currency uses decimal places
     */
    public function usesDecimals(string $code): bool
    {
        $decimals = $this->getDecimals($code);
        return $decimals > 0;
    }

    /**
     * Get minor unit (cents) for currency
     */
    public function getMinorUnit(float $amount, string $code): int
    {
        $decimals = $this->getDecimals($code);
        return (int) round($amount * pow(10, $decimals));
    }

    /**
     * Convert minor unit back to major amount
     */
    public function fromMinorUnit(int $minorAmount, string $code): float
    {
        $decimals = $this->getDecimals($code);
        return $minorAmount / pow(10, $decimals);
    }

    /**
     * Get default currency for store
     * @throws DatabaseException
     */
    public function getDefaultCurrency(): string
    {
        $query = "SELECT currency FROM commerce_stores LIMIT 1";
        return $this->database->query($query)->fetchColumn() ?? "USD";
    }

    /**
     * Validate amount for currency
     */
    public function validateAmount(float $amount, string $code): bool
    {
        $currency = $this->getCurrency($code);
        if (!$currency) {
            return $amount >= 0;
        }

        $minAmount = $currency['min_amount'] ?? 0;
        $maxAmount = $currency['max_amount'] ?? PHP_FLOAT_MAX;

        return $amount >= $minAmount && $amount <= $maxAmount;
    }

    /**
     * Get currency metadata
     */
    public function getMetadata(string $code): array
    {
        $currency = $this->getCurrency($code);
        return $currency['metadata'] ?? [];
    }

    /**
     * Refresh currency data from repository
     */
    public function refresh(): void
    {
        $this->currencies = Yaml::parseFile($this->repoFile);
        
        if (!$this->currencies) {
            throw new Exception("Failed to parse currency repository file: {$this->repoFile}");
        }
    }

    /**
     * Cache currency data for performance
     */
    public function cache(string $key, array $data, int $ttl = 3600): void
    {
        // Implementation would depend on caching system used
        // This is a placeholder for caching functionality
    }

    /**
     * Get cached currency data
     */
    public function getCached(string $key): ?array
    {
        // Implementation would depend on caching system used
        // This is a placeholder for caching functionality
        return null;
    }
}