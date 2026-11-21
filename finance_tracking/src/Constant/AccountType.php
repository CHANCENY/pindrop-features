<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Constant;

final class AccountType
{
    // Cash & Banking (Assets)
    public const CHECKING = 'Checking';
    public const SAVINGS = 'Savings';
    public const CASH = 'Cash';
    public const DIGITAL_WALLET = 'Digital Wallet';
    public const MONEY_MARKET = 'Money Market';
    public const CERTIFICATE_OF_DEPOSIT = 'Certificate of Deposit';

    // Credit & Debt (Liabilities)
    public const CREDIT_CARD = 'Credit Card';
    public const LINE_OF_CREDIT = 'Line of Credit';
    public const PERSONAL_LOAN = 'Personal Loan';
    public const AUTO_LOAN = 'Auto Loan';
    public const STUDENT_LOAN = 'Student Loan';
    public const MORTGAGE = 'Mortgage';
    public const BUY_NOW_PAY_LATER = 'Buy Now Pay Later';

    // Investments & Retirement (Assets)
    public const BROKERAGE = 'Brokerage';
    public const RETIREMENT = 'Retirement';
    public const HEALTH_SAVINGS = 'Health Savings';
    public const EDUCATION_SAVINGS = 'Education Savings';
    public const CRYPTO = 'Crypto';

    // Fixed Assets & Valuables
    public const REAL_ESTATE = 'Real Estate';
    public const VEHICLE = 'Vehicle';
    public const OTHER_ASSET = 'Other Asset';

    // Bills & Other
    public const HOME_UTILITIES = 'Home Utilities';
    public const OTHER_LIABILITY = 'Other Liability';

    /**
     * Returns all available account types.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return array_merge(self::assets(), self::liabilities());
    }

    /**
     * Returns all asset-based account types.
     *
     * @return string[]
     */
    public static function assets(): array
    {
        return [
            self::CHECKING,
            self::SAVINGS,
            self::CASH,
            self::DIGITAL_WALLET,
            self::MONEY_MARKET,
            self::CERTIFICATE_OF_DEPOSIT,
            self::BROKERAGE,
            self::RETIREMENT,
            self::HEALTH_SAVINGS,
            self::EDUCATION_SAVINGS,
            self::CRYPTO,
            self::REAL_ESTATE,
            self::VEHICLE,
            self::OTHER_ASSET,
        ];
    }

    /**
     * Returns all liability-based account types.
     *
     * @return string[]
     */
    public static function liabilities(): array
    {
        return [
            self::CREDIT_CARD,
            self::LINE_OF_CREDIT,
            self::PERSONAL_LOAN,
            self::AUTO_LOAN,
            self::STUDENT_LOAN,
            self::MORTGAGE,
            self::BUY_NOW_PAY_LATER,
            self::HOME_UTILITIES,
            self::OTHER_LIABILITY,
        ];
    }
}
