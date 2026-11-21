<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\Slot;

use Simp\Pindrop\Modules\finance_tracking\src\Service\Account;
use Simp\Pindrop\Modules\finance_tracking\src\Service\Transactions;
use Simp\Pindrop\Modules\finance_tracking\src\Transaction;
use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

class TransactionOccured implements SlotInterface
{
   
    public function handle(array $payload): void
    {
        $transaction_id = $payload['transaction_id'] ?? null;

        if ($transaction_id)
        {
            $transaction = new Transaction(getAppContainer()->get('database'), $transaction_id);

            // If transaction is income add to account selected for if expense substract from budget.
            if ($transaction->transaction_type === 'income') {
                $account = $transaction->account;
                $account->setAccountBalance($account->getAccountBalance()->add($transaction->amount));
                $account->save();
            }
            else {
                $budget = $transaction->budget;
                $transactions = $transaction->getTransactionWithSameBudget();
                foreach($transactions as $tr){
                    $budget->setUsedAmount($budget->getUsedAmount()->add($tr->amount));
                }
                $budget->save();
            }
        }
    }
}