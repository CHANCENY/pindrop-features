<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\Slot;


use Simp\Pindrop\Modules\finance_tracking\src\ActiveAccount;
use Simp\Pindrop\Modules\finance_tracking\src\Budget;
use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;


class TransactionDeleted implements SlotInterface
{

    public function handle(array $payload): void
    {
        $transaction = $payload['transaction'] ?? null;
        if (is_array($transaction)) {
            // reverse the amount transaction by its action.
            $amount = $transaction['amount'];
            $type = $transaction['transaction_type'];
            $account = $transaction['account'];
            $budget = $transaction['budget'];

            if ($type === 'income') {
                $activeAccount = new ActiveAccount(getAppContainer()->get('database'), $account);
                $activeAccount->setAccountBalance($activeAccount->getAccountBalance()->subtract((float) $amount));
                $activeAccount->save();
            } else {
                $budget = new Budget(getAppContainer()->get('database'), $budget);
                $budget->setUsedAmount($budget->getUsedAmount()->subtract((float) $amount));
                $budget->save();
            }
        }
    }
}