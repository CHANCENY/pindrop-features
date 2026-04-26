<?php

namespace Simp\Pindrop\Modules\pig_farmer\src\Services;


use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class FinanceManager
{
    private $db;
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
        return $this->db->fetchAll("SELECT * FROM pig_farmer_finances ORDER BY transaction_date DESC");
    }

    /**
     * @throws DatabaseException
     */
    public function getFinancesByPigId(int $pigId): array
    {
        return $this->db->fetchAll("SELECT * FROM pig_farmer_finances WHERE pig_id = ? ORDER BY transaction_date DESC", ...$i=[$pigId]);
    }

    /**
     * @throws DatabaseException
     */
    public function addFinanceRecord(array $data): bool
    {
        $query = "INSERT INTO pig_farmer_finances (transaction_date, type, category, amount, description, pig_id) VALUES (?, ?, ?, ?, ?, ?)";
        return $this->db->query($query, ...$i=[
            $data["transaction_date"],
            $data["type"],
            $data["category"],
            $data["amount"],
            $data["description"],
            $data["pig_id"]
        ])->rowCount() > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function getFinancialSummary(): array
    {
        $income = $this->db->fetch("SELECT SUM(amount) as total_income FROM pig_farmer_finances WHERE type = 'Income'");
        $expense = $this->db->fetch("SELECT SUM(amount) as total_expense FROM pig_farmer_finances WHERE type = 'Expense'");

        return [
            'total_income' => $income['total_income'] ?? 0,
            'total_expense' => $expense['total_expense'] ?? 0,
            'net_profit' => ($income['total_income'] ?? 0) - ($expense['total_expense'] ?? 0)
        ];
    }
}
