<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src;

use RuntimeException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\finance_tracking\src\Budget;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\Signals;
use Simp\Pindrop\Modules\signals_slots\src\Service\SignalBus;
use Simp\Price\lib\Price;

class Transaction
{
    public int $id;
    public string $transaction_type;
    public string $merchant;
    public Price $amount;
    public ?string $category;
    public string $transaction_date;
    public ?Budget $budget;
    public ?string $reference_note;
    public ?string $notes;
    public ?string $status;
    public ?string $created_at;
    public ActiveAccount $account;

    public function __construct(
        protected readonly DatabaseService $database_service,
        int $transaction_id
    ) {
        $this->load($transaction_id);
    }

    /**
     * Load transaction data from the database.
     */
    public function load(int $transaction_id): self
    {
        $transaction = $this->database_service
            ->table('finance__transaction')
            ->where('id', '=', $transaction_id)
            ->first();

        if (!$transaction) {
            throw new RuntimeException(
                "Transaction with ID {$transaction_id} was not found."
            );
        }

        $this->id = (int) $transaction['id'];
        $this->transaction_type = $transaction['transaction_type'];
        $this->merchant = $transaction['merchant'];
        $this->category = $transaction['category'];
        $this->transaction_date = $transaction['transaction_date'];
        $this->budget = !empty($transaction['budget']) ? new Budget($this->database_service, (int) $transaction['budget']) : null;
        $this->reference_note = $transaction['reference_note'];
        $this->notes = $transaction['notes'];
        $this->account = new ActiveAccount($this->database_service, $transaction['account']);
        $this->amount = new Price((float) $transaction['amount'], $this->account->getAccountCurrency());
        $this->status = $transaction['status'] ?? 'verified';
        $this->created_at = $transaction['created_at'] ?? null;

        return $this;
    }

    /**
     * Update transaction information.
     */
    public function update(array $data): bool
    {
        $allowed_fields = [
            'transaction_type',
            'merchant',
            'amount',
            'category',
            'transaction_date',
            'budget',
            'reference_note',
            'notes',
        ];

        $data = array_intersect_key($data, array_flip($allowed_fields));

        if (empty($data)) {
            return false;
        }

        $updated = $this->database_service
            ->table('finance__transaction')
            ->where('id', '=', $this->id)
            ->update($data);

        if ($updated) {
            $this->load($this->id);
        }

        return (bool) $updated;
    }

    /**
     * Delete this transaction.
     */
    public function delete(): bool
    {
        /** @var SignalBus $bus */
        $bus = \getAppContainer()->get(SignalBus::class);
        $bus->emit(Signals::TRANSACTION_DELETED,['transaction'=>$this->toArray()]);
        return (bool) $this->database_service
            ->table('finance__transaction')
            ->where('id', '=', $this->id)
            ->update(['status'=>'void']);
    }

    /**
     * Get transaction ID.
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * Check whether transaction is income.
     */
    public function isIncome(): bool
    {
        return $this->transaction_type === 'income';
    }

    /**
     * Check whether transaction is an expense.
     */
    public function isExpense(): bool
    {
        return $this->transaction_type === 'expense';
    }

    /**
     * Get formatted transaction amount.
     */
    public function getFormattedAmount(): string
    {
        return $this->amount->format();
    }

    /**
     * Get transaction details as an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'transaction_type' => $this->transaction_type,
            'merchant' => $this->merchant,
            'amount' => $this->amount->getAmount(),
            'category' => $this->category,
            'transaction_date' => $this->transaction_date,
            'budget' => $this->budget->id(),
            'reference_note' => $this->reference_note,
            'notes' => $this->notes,
        ];
    }


    /**
     * Create a new transaction.
     */
    public static function create( array $data): self {
        
        $required_fields = [
            'transaction_type',
            'merchant',
            'amount',
            'transaction_date',
        ];

        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                throw new RuntimeException(
                    "Missing required transaction field: {$field}"
                );
            }
        }

        if (!in_array($data['transaction_type'], ['income', 'expense'], true)) {
            throw new RuntimeException('Invalid transaction type.');
        }

        $transaction_data = [
            'transaction_type' => $data['transaction_type'],
            'merchant' => $data['merchant'],
            'amount' => $data['amount'],
            'category' => $data['category'] ?? null,
            'transaction_date' => $data['transaction_date'],
            'budget' => $data['budget'] ?? null,
            'reference_note' => $data['reference_note'] ?? null,
            'notes' => $data['notes'] ?? null,
            'account' => $data['account']
        ];

        $transaction_id = getAppContainer()->get('database')
            ->table('finance__transaction')
            ->insert($transaction_data);

        /** @var SignalBus $bus */
        $bus = \getAppContainer()->get(SignalBus::class);
        $bus->emit(Signals::TRANSACTION_OCCURED,['transaction_id'=> $transaction_id]);
        
        return new self(getAppContainer()->get('database'), (int) $transaction_id);
    }

    public function getTransactionWithSameReference(): array
    {
        $trs = $this->database_service->table('finance__transaction')
        ->where('reference_note', '=', $this->reference_note)
        ->where('id', '!=', $this->id())
        ->get();

        return array_map(function ($item) {
            return new Transaction($this->database_service, $item['id']);
        }, $trs);
    }

    public function getTransactionWithSameBudget(): array
    {
        $trs = $this->database_service->table('finance__transaction')
        ->where('budget', '=', $this->budget->id())
        ->whereNotNull('budget')
        ->get();

        return array_map(function ($item) {
            return new Transaction($this->database_service, $item['id']);
        }, $trs);
    }

}
