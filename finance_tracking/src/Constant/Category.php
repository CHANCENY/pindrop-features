<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Constant;

class Category
{
    // Income
    public const INCOME_SALARY = 'Salary';
    public const INCOME_BUSINESS = 'Business';
    public const INCOME_FREELANCE = 'Freelance';
    public const INCOME_INVESTMENT = 'Investment';
    public const INCOME_RENTAL = 'Rental';
    public const INCOME_GIFT = 'Gift';
    public const INCOME_INTEREST = 'Interest';
    public const INCOME_OTHER = 'Other Income';

    // Housing
    public const HOUSING_RENT = 'Rent';
    public const HOUSING_MORTGAGE = 'Mortgage';
    public const HOUSING_ELECTRICITY = 'Electricity';
    public const HOUSING_WATER = 'Water';
    public const HOUSING_INTERNET = 'Internet';
    public const HOUSING_MAINTENANCE = 'Home Maintenance';
    public const HOUSING_SECURITY = 'Security';

    // Food
    public const FOOD_GROCERIES = 'Groceries';
    public const FOOD_RESTAURANTS = 'Restaurants';
    public const FOOD_FAST_FOOD = 'Fast Food';
    public const FOOD_COFFEE = 'Coffee';
    public const FOOD_OTHER = 'Other Food';

    // Transportation
    public const TRANSPORT_FUEL = 'Fuel';
    public const TRANSPORT_PUBLIC = 'Public Transport';
    public const TRANSPORT_TAXI = 'Taxi';
    public const TRANSPORT_VEHICLE = 'Vehicle Maintenance';
    public const TRANSPORT_PARKING = 'Parking';
    public const TRANSPORT_INSURANCE = 'Vehicle Insurance';

    // Healthcare
    public const HEALTH_MEDICAL = 'Medical';
    public const HEALTH_MEDICATION = 'Medication';
    public const HEALTH_INSURANCE = 'Health Insurance';
    public const HEALTH_DENTAL = 'Dental';
    public const HEALTH_FITNESS = 'Fitness';

    // Personal
    public const PERSONAL_CLOTHING = 'Clothing';
    public const PERSONAL_CARE = 'Personal Care';
    public const PERSONAL_HAIR = 'Hair and Beauty';
    public const PERSONAL_EDUCATION = 'Education';
    public const PERSONAL_ENTERTAINMENT = 'Entertainment';
    public const PERSONAL_HOBBIES = 'Hobbies';

    // Family
    public const FAMILY_CHILDCARE = 'Childcare';
    public const FAMILY_SCHOOL = 'School Fees';
    public const FAMILY_ALLOWANCE = 'Family Allowance';
    public const FAMILY_GIFTS = 'Family Gifts';
    public const FAMILY_PETS = 'Pets';

    // Financial
    public const FINANCIAL_SAVINGS = 'Savings';
    public const FINANCIAL_INVESTMENTS = 'Investments';
    public const FINANCIAL_LOAN_PAYMENT = 'Loan Payment';
    public const FINANCIAL_CREDIT_CARD = 'Credit Card Payment';
    public const FINANCIAL_BANK_FEES = 'Bank Fees';
    public const FINANCIAL_TAXES = 'Taxes';

    // Shopping
    public const SHOPPING_ELECTRONICS = 'Electronics';
    public const SHOPPING_HOUSEHOLD = 'Household Items';
    public const SHOPPING_FURNITURE = 'Furniture';
    public const SHOPPING_GIFTS = 'Gifts';

    // Other
    public const OTHER_DONATIONS = 'Donations';
    public const OTHER_TRAVEL = 'Travel';
    public const OTHER_SUBSCRIPTIONS = 'Subscriptions';
    public const OTHER_CHARITY = 'Charity';
    public const OTHER_MISCELLANEOUS = 'Miscellaneous';

    /**
     * Get all categories.
     */
    public static function all(): array
    {
        return [
            self::INCOME_SALARY,
            self::INCOME_BUSINESS,
            self::INCOME_FREELANCE,
            self::INCOME_INVESTMENT,
            self::INCOME_RENTAL,
            self::INCOME_GIFT,
            self::INCOME_INTEREST,
            self::INCOME_OTHER,

            self::HOUSING_RENT,
            self::HOUSING_MORTGAGE,
            self::HOUSING_ELECTRICITY,
            self::HOUSING_WATER,
            self::HOUSING_INTERNET,
            self::HOUSING_MAINTENANCE,
            self::HOUSING_SECURITY,

            self::FOOD_GROCERIES,
            self::FOOD_RESTAURANTS,
            self::FOOD_FAST_FOOD,
            self::FOOD_COFFEE,
            self::FOOD_OTHER,

            self::TRANSPORT_FUEL,
            self::TRANSPORT_PUBLIC,
            self::TRANSPORT_TAXI,
            self::TRANSPORT_VEHICLE,
            self::TRANSPORT_PARKING,
            self::TRANSPORT_INSURANCE,

            self::HEALTH_MEDICAL,
            self::HEALTH_MEDICATION,
            self::HEALTH_INSURANCE,
            self::HEALTH_DENTAL,
            self::HEALTH_FITNESS,

            self::PERSONAL_CLOTHING,
            self::PERSONAL_CARE,
            self::PERSONAL_HAIR,
            self::PERSONAL_EDUCATION,
            self::PERSONAL_ENTERTAINMENT,
            self::PERSONAL_HOBBIES,

            self::FAMILY_CHILDCARE,
            self::FAMILY_SCHOOL,
            self::FAMILY_ALLOWANCE,
            self::FAMILY_GIFTS,
            self::FAMILY_PETS,

            self::FINANCIAL_SAVINGS,
            self::FINANCIAL_INVESTMENTS,
            self::FINANCIAL_LOAN_PAYMENT,
            self::FINANCIAL_CREDIT_CARD,
            self::FINANCIAL_BANK_FEES,
            self::FINANCIAL_TAXES,

            self::SHOPPING_ELECTRONICS,
            self::SHOPPING_HOUSEHOLD,
            self::SHOPPING_FURNITURE,
            self::SHOPPING_GIFTS,

            self::OTHER_DONATIONS,
            self::OTHER_TRAVEL,
            self::OTHER_SUBSCRIPTIONS,
            self::OTHER_CHARITY,
            self::OTHER_MISCELLANEOUS,
        ];
    }

    /**
     * Check whether a category exists.
     */
    public static function exists(string $category): bool
    {
        return in_array($category, self::all(), true);
    }
}
