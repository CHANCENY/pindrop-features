<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Constant;

class TransactionCategory
{
    public const FOOD = 'Food';
    public const GROCERIES = 'Groceries';
    public const TRANSPORT = 'Transport';
    public const SHOPPING = 'Shopping';
    public const ENTERTAINMENT = 'Entertainment';
    public const HEALTH = 'Health';
    public const EDUCATION = 'Education';
    public const UTILITIES = 'Utilities';
    public const HOUSING = 'Housing';
    public const RENT = 'Rent';
    public const TRAVEL = 'Travel';
    public const SUBSCRIPTIONS = 'Subscriptions';
    public const PERSONAL_CARE = 'Personal Care';
    public const INSURANCE = 'Insurance';
    public const TAXES = 'Taxes';
    public const DEBT = 'Debt';
    public const SAVINGS = 'Savings';
    public const INVESTMENT = 'Investment';
    public const SALARY = 'Salary';
    public const BUSINESS = 'Business';
    public const FREELANCE = 'Freelance';
    public const GIFTS = 'Gifts';
    public const OTHER = 'Other';

    /**
     * Category colors.
     */
    public const COLORS = [
        self::FOOD => '#F59E0B',
        self::GROCERIES => '#84CC16',
        self::TRANSPORT => '#3B82F6',
        self::SHOPPING => '#EC4899',
        self::ENTERTAINMENT => '#8B5CF6',
        self::HEALTH => '#EF4444',
        self::EDUCATION => '#6366F1',
        self::UTILITIES => '#EAB308',
        self::HOUSING => '#64748B',
        self::RENT => '#0EA5E9',
        self::TRAVEL => '#14B8A6',
        self::SUBSCRIPTIONS => '#A855F7',
        self::PERSONAL_CARE => '#F472B6',
        self::INSURANCE => '#475569',
        self::TAXES => '#78716C',
        self::DEBT => '#DC2626',
        self::SAVINGS => '#22C55E',
        self::INVESTMENT => '#059669',
        self::SALARY => '#16A34A',
        self::BUSINESS => '#2563EB',
        self::FREELANCE => '#06B6D4',
        self::GIFTS => '#D946EF',
        self::OTHER => '#94A3B8',
    ];

    /**
     * Get all available categories.
     */
    public static function all(): array
    {
        return [
            self::FOOD,
            self::GROCERIES,
            self::TRANSPORT,
            self::SHOPPING,
            self::ENTERTAINMENT,
            self::HEALTH,
            self::EDUCATION,
            self::UTILITIES,
            self::HOUSING,
            self::RENT,
            self::TRAVEL,
            self::SUBSCRIPTIONS,
            self::PERSONAL_CARE,
            self::INSURANCE,
            self::TAXES,
            self::DEBT,
            self::SAVINGS,
            self::INVESTMENT,
            self::SALARY,
            self::BUSINESS,
            self::FREELANCE,
            self::GIFTS,
            self::OTHER,
        ];
    }

    /**
     * Get categories for expenses.
     */
    public static function expenses(): array
    {
        return [
            self::FOOD,
            self::GROCERIES,
            self::TRANSPORT,
            self::SHOPPING,
            self::ENTERTAINMENT,
            self::HEALTH,
            self::EDUCATION,
            self::UTILITIES,
            self::HOUSING,
            self::RENT,
            self::TRAVEL,
            self::SUBSCRIPTIONS,
            self::PERSONAL_CARE,
            self::INSURANCE,
            self::TAXES,
            self::DEBT,
            self::OTHER,
        ];
    }

    /**
     * Get categories for income.
     */
    public static function income(): array
    {
        return [
            self::SALARY,
            self::BUSINESS,
            self::FREELANCE,
            self::INVESTMENT,
            self::GIFTS,
            self::OTHER,
        ];
    }

    /**
     * Check whether a category exists.
     */
    public static function exists(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    /**
     * Get color for a category.
     */
    public static function color(string $category): string
    {
        return self::COLORS[$category] ?? self::COLORS[self::OTHER];
    }

    /**
     * Get category and its color.
     */
    public static function get(string $category): array
    {
        if (!self::exists($category)) {
            $category = self::OTHER;
        }

        return [
            'name' => $category,
            'color' => self::color($category),
        ];
    }

    /**
     * Get all categories with their colors.
     */
    public static function withColors(): array
    {
        return array_map(
            fn(string $category) => [
                'name' => $category,
                'color' => self::color($category),
            ],
            self::all()
        );
    }

    /**
     * Get category options for HTML select elements.
     */
    public static function options(?string $type = null): array
    {
        $categories = match ($type) {
            'income' => self::income(),
            'expense' => self::expenses(),
            default => self::all(),
        };

        return array_combine($categories, $categories);
    }
}
