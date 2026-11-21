<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Constant;

final class Preference
{
    public const WEEKLY_MONEY_NOTE = 'Weekly money note';
    public const BUDGET_ALERTS = 'Budget alerts';
    public const MARKETING_EMAILS = 'Marketing emails';
    public const LOW_BALANCE_ALERTS = 'Low balance alerts';
    public const LARGE_TRANSACTION_ALERTS = 'Large transaction alerts';
    public const BILL_PAYMENT_REMINDERS = 'Bill payment reminders';
    public const MONTHLY_ACCOUNT_STATEMENTS = 'Monthly account statements';
    public const SAVINGS_GOAL_MILESTONES = 'Savings goal milestones';
    public const SECURITY_AND_LOGIN_ALERTS = 'Security and login alerts';

    /**
     * Returns all available preference names.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::WEEKLY_MONEY_NOTE,
            self::BUDGET_ALERTS,
            self::MARKETING_EMAILS,
            self::LOW_BALANCE_ALERTS,
            self::LARGE_TRANSACTION_ALERTS,
            self::BILL_PAYMENT_REMINDERS,
            self::MONTHLY_ACCOUNT_STATEMENTS,
            self::SAVINGS_GOAL_MILESTONES,
            self::SECURITY_AND_LOGIN_ALERTS,
        ];
    }
}
