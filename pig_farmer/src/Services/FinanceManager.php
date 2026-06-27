<?php

namespace Simp\Pindrop\Modules\pig_farmer\src\Services;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class FinanceManager
{
    private DatabaseService $db;
    private $logger;

    public function __construct(DatabaseService $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * @throws DatabaseException
     */
    public function getAllFinances(): array
    {
        return $this->db->table('pig_farmer_finances')
            ->latest('transaction_date')
            ->get();
    }

    /**
     * @throws DatabaseException
     */
    public function getFinancesByPigId(int $pigId): array
    {
        return $this->db->table('pig_farmer_finances')
            ->where('pig_id', '=', $pigId)
            ->latest('transaction_date')
            ->get();
    }

    /**
     * @throws DatabaseException
     */
    public function addFinanceRecord(array $data): bool
    {
        $id = $this->db->table('pig_farmer_finances')->insert([
            'transaction_date' => $data['transaction_date'],
            'type'             => $data['type'],
            'category'         => $data['category'],
            'amount'           => $data['amount'],
            'description'      => $data['description'],
            'pig_id'           => $data['pig_id'],
        ]);
        return $id > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function getFinancialSummary(): array
    {
        $income = $this->db->table('pig_farmer_finances')
            ->select(['SUM(amount) as total_income'])
            ->where('type', '=', 'Income')
            ->first();

        $expense = $this->db->table('pig_farmer_finances')
            ->select(['SUM(amount) as total_expense'])
            ->where('type', '=', 'Expense')
            ->first();

        return [
            'total_income'  => $income['total_income'] ?? 0,
            'total_expense' => $expense['total_expense'] ?? 0,
            'net_profit'    => ($income['total_income'] ?? 0) - ($expense['total_expense'] ?? 0),
        ];
    }

    /**
     * @throws DatabaseException
     */
    public function updateFinanceRecord(int $id, array $data): bool
    {
        return $this->db->table('pig_farmer_finances')
            ->where('id', '=', $id)
            ->update([
                'transaction_date' => $data['transaction_date'],
                'type'             => $data['type'],
                'category'         => $data['category'],
                'amount'           => $data['amount'],
                'description'      => $data['description'],
                'pig_id'           => $data['pig_id'],
            ]) > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function deleteFinanceRecord(int $id): bool
    {
        return $this->db->table('pig_farmer_finances')
            ->where('id', '=', $id)
            ->delete() > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function getFinanceById(int $id): ?array
    {
        return $this->db->table('pig_farmer_finances')
            ->where('id', '=', $id)
            ->first();
    }

    /**
     * @throws DatabaseException
     */
    public function getFinanceDataForChart(): array
    {
        return $this->db->table('pig_farmer_finances')
            ->select([
                'transaction_date',
                "SUM(CASE WHEN type = 'Income' THEN amount ELSE 0 END) as income",
                "SUM(CASE WHEN type = 'Expense' THEN amount ELSE 0 END) as expense",
            ])
            ->groupBy('transaction_date')
            ->orderBy('transaction_date', 'ASC')
            ->limit(30)
            ->get();
    }
}
