<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Service;

use InvalidArgumentException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\finance_tracking\src\ActiveAccount;
use Simp\Pindrop\Modules\finance_tracking\src\Transaction;

class Transactions
{
    protected ?ActiveAccount $active_account;

    public function __construct(
        protected readonly DatabaseService $database_service,
        Account $account
    ) {
        $this->active_account = $account->loadActiveAccount();
    }

    /**
     * Get all transactions for the active account.
     */
    public function getTransactions(): array
    {
        $transactions = $this->database_service
            ->table('finance__transaction')
            ->where('account', '=', $this->active_account?->id())
            ->orderBy('transaction_date', 'DESC')
            ->get();

        return array_map(
            fn($transaction) => new Transaction(
                $this->database_service,
                (int) $transaction['id']
            ),
            $transactions
        );
    }

    /**
     * Find a transaction by ID.
     */
    public function find(int $transaction_id): ?Transaction
    {
        $transaction = $this->database_service
            ->table('finance__transaction')
            ->where('id', '=', $transaction_id)
            ->where('account', '=', $this->active_account?->id())
            ->first();

        if (!$transaction) {
            return null;
        }

        return new Transaction(
            $this->database_service,
            $transaction_id
        );
    }

    /**
     * Create a transaction.
     */
    public function create(array $data): Transaction
    {
        $data['account'] = $this->active_account?->id();

        return Transaction::create($data);
    }

    /**
     * Get income transactions.
     */
    public function getIncome(): array
    {
        return $this->getByType('income');
    }

    /**
     * Get expense transactions.
     */
    public function getExpenses(): array
    {
        return $this->getByType('expense');
    }

    /**
     * Get transactions by type.
     */
    public function getByType(string $type): array
    {
        if (!in_array($type, ['income', 'expense'], true)) {
            throw new InvalidArgumentException('Invalid transaction type.');
        }

        $transactions = $this->database_service
            ->table('finance__transaction')
            ->where('account', '=', $this->active_account?->id())
            ->where('transaction_type', '=', $type)
            ->orderBy('transaction_date', 'DESC')
            ->get();

        return array_map(
            fn($transaction) => new Transaction(
                $this->database_service,
                (int) $transaction['id']
            ),
            $transactions
        );
    }

    /**
     * Get transactions by category.
     */
    public function getByCategory(string $category): array
    {
        $transactions = $this->database_service
            ->table('finance__transaction')
            ->where('account', '=', $this->active_account?->id())
            ->where('category', '=', $category)
            ->orderBy('transaction_date', 'DESC')
            ->get();

        return array_map(
            fn($transaction) => new Transaction(
                $this->database_service,
                (int) $transaction['id']
            ),
            $transactions
        );
    }

    /**
     * Get transactions associated with a budget.
     */
    public function getByBudget(int $budget_id): array
    {
        $transactions = $this->database_service
            ->table('finance__transaction')
            ->where('account', '=', $this->active_account?->id())
            ->where('budget', '=', $budget_id)
            ->orderBy('transaction_date', 'DESC')
            ->get();

        return array_map(
            fn($transaction) => new Transaction(
                $this->database_service,
                (int) $transaction['id']
            ),
            $transactions
        );
    }

    /**
     * Get transactions within a date range.
     */
    public function getBetweenDates(
        string $start_date,
        string $end_date
    ): array {
        $transactions = $this->database_service
            ->table('finance__transaction')
            ->where('account', '=', $this->active_account?->id())
            ->where('transaction_date', '>=', $start_date)
            ->where('transaction_date', '<=', $end_date)
            ->orderBy('transaction_date', 'DESC')
            ->get();

        return array_map(
            fn($transaction) => new Transaction(
                $this->database_service,
                (int) $transaction['id']
            ),
            $transactions
        );
    }

    /**
     * Get total income.
     */
    public function getTotalIncome(): float
    {
        return $this->calculateTotal('income');
    }

    /**
     * Get total expenses.
     */
    public function getTotalExpenses(): float
    {
        return $this->calculateTotal('expense');
    }

    /**
     * Calculate total transactions by type.
     */
    protected function calculateTotal(string $type): float
    {
        $transactions = $this->getByType($type);

        return array_reduce(
            $transactions,
            fn(float $total, Transaction $transaction) =>
                $total + $transaction->amount->getAmount(),
            0.0
        );
    }

    /**
     * Get account transaction balance.
     */
    public function getBalance(): float
    {
        return $this->getTotalIncome() - $this->getTotalExpenses();
    }

    /**
     * Count all transactions.
     */
    public function count(): int
    {
        return $this->database_service->table('finance__transaction')
        ->where('account','=', $this->active_account?->id())->count();
    }

    /**
     * Delete a transaction belonging to the active account.
     */
    public function delete(int $transaction_id): bool
    {
        $transaction = $this->find($transaction_id);

        if (!$transaction) {
            return false;
        }

        return $transaction->delete();
    }

    /**
     * Get income for the current month.
     */
    public function getIncomeThisMonth(): float
    {
        return $this->getTotalBetween(
            'income',
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59')
        );
    }

    /**
     * Get income for the current year.
     */
    public function getIncomeThisYear(): float
    {
        return $this->getTotalBetween(
            'income',
            date('Y-01-01 00:00:00'),
            date('Y-12-31 23:59:59')
        );
    }

    /**
     * Get expenses for the current month.
     */
    public function getExpensesThisMonth(): float
    {
        return $this->getTotalBetween(
            'expense',
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59')
        );
    }

    /**
     * Get expenses for the current year.
     */
    public function getExpensesThisYear(): float
    {
        return $this->getTotalBetween(
            'expense',
            date('Y-01-01 00:00:00'),
            date('Y-12-31 23:59:59')
        );
    }

    /**
     * Get today's income.
     */
    public function getIncomeToday(): float
    {
        return $this->getTotalBetween(
            'income',
            date('Y-m-d 00:00:00'),
            date('Y-m-d 23:59:59')
        );
    }

    /**
     * Get today's expenses.
     */
    public function getExpensesToday(): float
    {
        return $this->getTotalBetween(
            'expense',
            date('Y-m-d 00:00:00'),
            date('Y-m-d 23:59:59')
        );
    }

    /**
     * Get net balance for the current month.
     */
    public function getBalanceThisMonth(): float
    {
        return $this->getIncomeThisMonth()
            - $this->getExpensesThisMonth();
    }

    /**
     * Get net balance for the current year.
     */
    public function getBalanceThisYear(): float
    {
        return $this->getIncomeThisYear()
            - $this->getExpensesThisYear();
    }

    /**
     * Get today's net balance.
     */
    public function getBalanceToday(): float
    {
        return $this->getIncomeToday()
            - $this->getExpensesToday();
    }

    /**
     * Get total income minus expenses for all time.
     */
    public function getNetBalance(): float
    {
        return $this->getTotalIncome()
            - $this->getTotalExpenses();
    }

    /**
     * Count transactions within a date range.
     */
    public function countBetween(
        string $start_date,
        string $end_date,
        ?string $type = null
    ): int {
        return count($this->getRowsBetween(
            $start_date,
            $end_date,
            $type
        ));
    }

    /**
     * Count income transactions this month.
     */
    public function countIncomeThisMonth(): int
    {
        return $this->countBetween(
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59'),
            'income'
        );
    }

    /**
     * Count expense transactions this month.
     */
    public function countExpensesThisMonth(): int
    {
        return $this->countBetween(
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59'),
            'expense'
        );
    }

    /**
     * Count transactions this year.
     */
    public function countThisYear(): int
    {
        return $this->countBetween(
            date('Y-01-01 00:00:00'),
            date('Y-12-31 23:59:59')
        );
    }

    /**
     * Get average transaction amount.
     */
    public function getAverageTransaction(
        ?string $type = null,
        ?string $start_date = null,
        ?string $end_date = null
    ): float {
        $transactions = $this->getRowsBetween(
            $start_date ?? '1000-01-01 00:00:00',
            $end_date ?? '9999-12-31 23:59:59',
            $type
        );

        if (empty($transactions)) {
            return 0.0;
        }

        $total = array_sum(
            array_map(
                fn($transaction) => (float) $transaction['amount'],
                $transactions
            )
        );

        return $total / count($transactions);
    }

    /**
     * Get average expense this month.
     */
    public function getAverageExpenseThisMonth(): float
    {
        return $this->getAverageTransaction(
            'expense',
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59')
        );
    }

    /**
     * Get average income this month.
     */
    public function getAverageIncomeThisMonth(): float
    {
        return $this->getAverageTransaction(
            'income',
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59')
        );
    }

    /**
     * Get transaction totals grouped by category.
     */
    public function getCategoryBreakdown(
        string $type,
        ?string $start_date = null,
        ?string $end_date = null
    ): array {
        if (!in_array($type, ['income', 'expense'], true)) {
            throw new \InvalidArgumentException('Invalid transaction type.');
        }

        $transactions = $this->getRowsBetween(
            $start_date ?? '1000-01-01 00:00:00',
            $end_date ?? '9999-12-31 23:59:59',
            $type
        );

        $categories = [];

        foreach ($transactions as $transaction) {
            $category = $transaction['category'] ?: 'Other';

            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'category' => $category,
                    'amount' => 0.0,
                    'count' => 0,
                    'percentage' => 0.0,
                ];
            }

            $categories[$category]['amount'] += (float) $transaction['amount'];
            $categories[$category]['count']++;
        }

        $total = array_sum(
            array_column($categories, 'amount')
        );

        foreach ($categories as &$category) {
            $category['percentage'] = $total > 0
                ? round(($category['amount'] / $total) * 100, 2)
                : 0.0;
        }

        unset($category);

        usort(
            $categories,
            fn($a, $b) => $b['amount'] <=> $a['amount']
        );

        return array_values($categories);
    }

    /**
     * Get expense breakdown for this month.
     */
    public function getExpenseBreakdownThisMonth(): array
    {
        return $this->getCategoryBreakdown(
            'expense',
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59')
        );
    }

    /**
     * Get income breakdown for this month.
     */
    public function getIncomeBreakdownThisMonth(): array
    {
        return $this->getCategoryBreakdown(
            'income',
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59')
        );
    }

    /**
     * Get monthly financial comparison.
     */
    public function getMonthlyComparison(): array
    {
        $current = new \DateTimeImmutable('first day of this month');
        $previous = $current->modify('-1 month');

        $current_start = $current->format('Y-m-01 00:00:00');
        $current_end = $current->modify('+1 month')->format('Y-m-01 00:00:00');

        $previous_start = $previous->format('Y-m-01 00:00:00');
        $previous_end = $current_start;

        $current_income = $this->getTotalBetween(
            'income',
            $current_start,
            $current_end
        );

        $previous_income = $this->getTotalBetween(
            'income',
            $previous_start,
            $previous_end
        );

        $current_expenses = $this->getTotalBetween(
            'expense',
            $current_start,
            $current_end
        );

        $previous_expenses = $this->getTotalBetween(
            'expense',
            $previous_start,
            $previous_end
        );

        $current_balance = $current_income - $current_expenses;
        $previous_balance = $previous_income - $previous_expenses;

        return [
            'income' => [
                'current' => $current_income,
                'previous' => $previous_income,
                'difference' => $current_income - $previous_income,
                'percentage' => $this->percentageChange(
                    $previous_income,
                    $current_income
                ),
            ],

            'expenses' => [
                'current' => $current_expenses,
                'previous' => $previous_expenses,
                'difference' => $current_expenses - $previous_expenses,
                'percentage' => $this->percentageChange(
                    $previous_expenses,
                    $current_expenses
                ),
            ],

            'balance' => [
                'current' => $current_balance,
                'previous' => $previous_balance,
                'difference' => $current_balance - $previous_balance,
                'percentage' => $this->percentageChange(
                    $previous_balance,
                    $current_balance
                ),
            ],
        ];
    }

    /**
     * Calculate percentage change.
     */
    protected function percentageChange(
        float $previous,
        float $current
    ): ?float {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round(
            (($current - $previous) / abs($previous)) * 100,
            2
        );
    }

    /**
     * Get raw transaction records within a date range.
     */
    protected function getRowsBetween(
        string $start_date,
        string $end_date,
        ?string $type = null
    ): array {
        $query = $this->database_service
            ->table('finance__transaction')
            ->where('account', '=', $this->active_account?->id())
            ->where('transaction_date', '>=', $start_date)
            ->where('transaction_date', '<', $end_date);

        if ($type !== null) {
            if (!in_array($type, ['income', 'expense'], true)) {
                throw new \InvalidArgumentException('Invalid transaction type.');
            }

            $query->where('transaction_type', '=', $type);
        }

        return $query->get();
    }

    /**
     * Calculate transaction totals within a date range.
     */
    protected function getTotalBetween(
        string $type,
        string $start_date,
        string $end_date
    ): float {
        $transactions = $this->getRowsBetween(
            $start_date,
            $end_date,
            $type
        );

        return array_reduce(
            $transactions,
            fn(float $total, array $transaction) =>
                $total + (float) $transaction['amount'],
            0.0
        );
    }

    /**
     * Get dashboard financial statistics.
     */
    public function getStatistics(): array
    {
        return [
            'income' => [
                'today' => $this->getIncomeToday(),
                'this_month' => $this->getIncomeThisMonth(),
                'this_year' => $this->getIncomeThisYear(),
                'all_time' => $this->getTotalIncome(),
            ],

            'expenses' => [
                'today' => $this->getExpensesToday(),
                'this_month' => $this->getExpensesThisMonth(),
                'this_year' => $this->getExpensesThisYear(),
                'all_time' => $this->getTotalExpenses(),
            ],

            'balance' => [
                'today' => $this->getBalanceToday(),
                'this_month' => $this->getBalanceThisMonth(),
                'this_year' => $this->getBalanceThisYear(),
                'all_time' => $this->getNetBalance(),
            ],

            'transactions' => [
                'total' => $this->count(),
                'this_month' => $this->countBetween(
                    date('Y-m-01 00:00:00'),
                    date('Y-m-t 23:59:59')
                ),
                'this_year' => $this->countThisYear(),
                'income_this_month' => $this->countIncomeThisMonth(),
                'expenses_this_month' => $this->countExpensesThisMonth(),
            ],

            'average' => [
                'income_this_month' => $this->getAverageIncomeThisMonth(),
                'expenses_this_month' => $this->getAverageExpenseThisMonth(),
            ],

            'expense_categories' => $this->getExpenseBreakdownThisMonth(),

            'comparison' => $this->getMonthlyComparison(),
        ];
    }

    
}
