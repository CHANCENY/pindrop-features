<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Constant;

class Signals
{
    public const CURRENCY_CHANGED = 'finance_tracking.currency.changed';

    public const TRANSACTION_OCCURED = 'finance_tracking.transaction.occured';

    public const BUDGET_CREATED = 'finance_tracking.budget.created';

    public const TRANSACTION_DELETED = 'finance_tracking.transaction.deleted';
}