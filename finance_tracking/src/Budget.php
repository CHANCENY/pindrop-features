<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\Signals;
use Simp\Pindrop\Modules\signals_slots\src\Service\SignalBus;
use Simp\Price\lib\Price;
use Throwable;

class Budget
{
    private int $id;
    private string $name;
    private string $monthly_limit;
    private string $category;
    private string $starting_date;
    private string $reset_cadence;
    private string $alert_at;
    private int $account_id;

    private float $budget_amount;
    private float $used_amount;

    public function __construct(
        protected readonly DatabaseService $database_service,
        int $budget_id
    ) {
        $budget = $this->database_service
            ->table('finance__budget')
            ->where('id', '=', $budget_id)
            ->first();

        if (!$budget) {
            throw new \RuntimeException("Budget with ID {$budget_id} was not found.");
        }

        $this->id = (int) $budget['id'];
        $this->name = $budget['budget_name'];
        $this->monthly_limit = (string) $budget['monthly_limit'];
        $this->category = $budget['category'];
        $this->starting_date = $budget['starting_date'];
        $this->reset_cadence = $budget['reset_cadence'];
        $this->alert_at = (string) $budget['alert_at'];
        $this->account_id = (int) $budget['account'];
        $this->used_amount = (float) $budget['used_amount'];
        $this->budget_amount = (float) $budget['budget_amount'];
    }

    public function id(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMonthlyLimit(): string
    {
        return $this->monthly_limit;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getStartDate(): string
    {
        return $this->starting_date;
    }

    public function getResetCadence(): string
    {
        return $this->reset_cadence;
    }

    public function getAlertAtPercentage(): string
    {
        return $this->alert_at;
    }

    public function getUsedAmount(): Price
    {
        return new Price($this->used_amount, $this->getAccount()->getAccountCurrency());
    }

    public function getBudgetAmount(): Price
    {
        return new Price($this->budget_amount, $this->getAccount()->getAccountCurrency());
    }

    public function getAccount(): ActiveAccount
    {
        return new ActiveAccount($this->database_service, $this->account_id);
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function setMonthlyLimit(string $monthly_limit): static
    {
        $this->monthly_limit = $monthly_limit;
        return $this;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function setStartDate(string $starting_date): static
    {
        $this->starting_date = $starting_date;
        return $this;
    }

    public function setResetCadence(string $reset_cadence): static
    {
        $this->reset_cadence = $reset_cadence;
        return $this;
    }

    public function setAlertAtPercentage(string $alert_at): static
    {
        $this->alert_at = $alert_at;
        return $this;
    }

    public function setAccount(int $account_id): static
    {
        $this->account_id = $account_id;
        return $this;
    }

    public function setBudgetAmount(float|Price $amount): static
    {
        $amount = is_float($amount) ? $amount : $amount->getAmount();
        $this->budget_amount = $amount;
        return $this;
    }

    public function setUsedAmount(float|Price $amount): static
    {
        $amount = is_float($amount) ? $amount : $amount->getAmount();
        $this->used_amount = $amount;
        return $this;
    }

    public function save(): void
    {
        $this->database_service
            ->table('finance__budget')
            ->where('id', '=', $this->id)
            ->update([
                'budget_name' => $this->name,
                'monthly_limit' => $this->monthly_limit,
                'category' => $this->category,
                'starting_date' => $this->starting_date,
                'reset_cadence' => $this->reset_cadence,
                'alert_at' => $this->alert_at,
                'account' => $this->account_id,
                'used_amount' => $this->used_amount,
                'budget_amount' => $this->budget_amount
            ]);
    }


    public static function create(
        string $name,
        string $monthly_limit,
        string $category,
        string $starting_date,
        string $reset_cadence,
        string $alert_at,
        int $account_id
    ): Budget {
        $budget_id = getAppContainer()->get('database')
            ->table('finance__budget')
            ->insert([
                'budget_name' => $name,
                'monthly_limit' => $monthly_limit,
                'category' => $category,
                'starting_date' => $starting_date,
                'reset_cadence' => $reset_cadence,
                'alert_at' => $alert_at,
                'account' => $account_id,
            ]);

        /** @var SignalBus $bus */
        $bus = \getAppContainer()->get(SignalBus::class);
        $bus->emit(Signals::BUDGET_CREATED,['budget_id'=> $budget_id]);

        return new Budget(getAppContainer()->get('database'), (int) $budget_id);
    }

    public function getUsedPercentage(): float
    {
        try{
            return $this->getUsedAmount()->divide($this->getBudgetAmount())->multiply(100)->roundUp()->getAmount();
        }catch(Throwable){
            return 0;
        }
    }

    public function delete(): bool
    {
        return $this->database_service->table('finance__budget')->delete() > 0;
    }
}
