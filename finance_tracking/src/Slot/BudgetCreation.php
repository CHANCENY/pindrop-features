<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\Slot;


use Simp\Pindrop\Modules\finance_tracking\src\Budget;
use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

class BudgetCreation implements SlotInterface
{
    public function handle(array $payload): void
    {
        $budget_id = $payload['budget_id'] ?? null;

        if ($budget_id) {
            $budget = new Budget(getAppContainer()->get('database'), $budget_id);
            $account = $budget->getAccount();

            if ($account->getAccountBalance()->isGreaterThan($budget->getBudgetAmount()) || $account->getAccountBalance()->isEqual($budget->getBudgetAmount())) {
                $account->setAccountBalance($account->getAccountBalance()->subtract($budget->getBudgetAmount()));
                $account->save();

            } else {
                $budget->delete();
            }
        }
    }
}