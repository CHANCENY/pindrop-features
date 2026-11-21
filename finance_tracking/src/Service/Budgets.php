<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Service;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\finance_tracking\src\ActiveAccount;
use Simp\Pindrop\Modules\finance_tracking\src\Budget;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\Signals;
use Simp\Pindrop\Modules\signals_slots\src\Service\SignalBus;

class Budgets
{
    public function __construct(protected readonly DatabaseService $databaseService)
    {
        
    }

    public function getAccountBudgets(ActiveAccount $activeAccount): array
    {
        $budgets = $this->databaseService->table('finance__budget')
        ->select(['id'])
        ->where('account', '=', $activeAccount->id())
        ->get();
        return array_map(function (array $budget) {
            return new Budget($this->databaseService, $budget['id']);
        }, $budgets);
    }

    public function getBudgetById(int $budgetId): Budget
    {
        return new Budget($this->databaseService, $budgetId);
    }

    public function createBudget(ActiveAccount $activeAccount, string $name, string $monthlyLimit, string $category, string $startingDate, string $resetCadence, string $alertAt): Budget
    {
        $budgetId = $this->databaseService->table('finance__budget')->insert([
            'budget_name' => $name,
            'monthly_limit' => $monthlyLimit,
            'category' => $category,
            'starting_date' => $startingDate,
            'reset_cadence' => $resetCadence,
            'alert_at' => $alertAt,
            'account' => $activeAccount->id(),
            'budget_amount' => (float) $monthlyLimit
        ]);

        /** @var SignalBus $bus */
        $bus = \getAppContainer()->get(SignalBus::class);
        $bus->emit(Signals::BUDGET_CREATED,['budget_id'=> $budgetId]);

        return new Budget($this->databaseService, (int) $budgetId);
    }
}