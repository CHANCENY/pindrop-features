<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use DateTime;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Transaction
{
    protected string $transaction = "transactions";

    protected array $transaction_type = ['Income','Expense'];

    protected array $status = ['Cleared','Paid','Pending','Overdue'];

    protected array $payment_method = ['Bank Transfer','Cash','Cheque','Credit', 'Mobile Wallet', 'Purchase Order'];


    public function __construct(protected DatabaseService $database_service, LoggerInterface $loggerInterface) {}

    public function addTransaction(array $data): ?int {
        if (isset($data['status']) && !in_array($data['status'], $this->status)){
            return null;
        }

        if (isset($data['payment_method']) && !in_array($data['payment_method'], $this->payment_method)) {
            return null;
        }

        if (isset($data['transaction_type']) && !in_array($data['transaction_type'], $this->transaction_type)) {
            return null;
        }

        return $this->database_service->table($this->transaction)->insert($data);
    }

     public function updateTransaction(int $transaction_id, array $data): ?int {
        if (isset($data['status']) && !in_array($data['status'], $this->status)){
            return null;
        }

        if (isset($data['payment_method']) && !in_array($data['payment_method'], $this->payment_method)) {
            return null;
        }

        if (isset($data['transaction_type']) && !in_array($data['transaction_type'], $this->transaction_type)) {
            return null;
        }

        return $this->database_service->table($this->transaction)->where('transaction_id', '=', $transaction_id)->update($data);
    }


    public function getAllTransactions(): array {
        return $this->database_service->table($this->transaction)->orderBy('transaction_date','DESC')->get();
    }

    public function getTransactionsByType(string $type): array {
        return $this->database_service->table($this->transaction)->where('transaction_type', '=', $type)->orderBy('transaction_date','DESC')->get();
    }

    public function getTransaction(int $id): ?array {
        return $this->database_service->table($this->transaction)->where('transaction_id','=', $id)->first();
    }

    public function getTransactionsByStatus(string $status): array {
        return $this->database_service->table($this->transaction)->where('status', '=', $status)->orderBy('transaction_date','DESC')->get();
    }

     public function getTransactionsByMethod(string $method): array {
        return $this->database_service->table($this->transaction)->where('payment_method', '=', $method)->orderBy('transaction_date','DESC')->get();
    }

    public function getYearStatics(?string $year = null): array {
        $filter_year = empty($year) ? new DateTime('now')->format('Y') : $year;
       
        $startDate = "{$filter_year}-01-01";
        $endDate   = "{$filter_year}-12-31";
        
        $st = [
            'year_expense' => $this->database_service->table($this->transaction)->whereBetween('transaction_date',$startDate, $endDate)
                              ->where('transaction_type', '=', 'Expense')->where('status', '=', 'Paid')->select(["SUM(amount) AS total_amount"])->first()['total_amount'],
            
            'year_income'  => $this->database_service->table($this->transaction)->whereBetween('transaction_date',$startDate, $endDate)
                              ->where('transaction_type', '=', 'Income')->where('status', '=', 'Cleared')->select(["SUM(amount) AS total_amount"])->first()['total_amount'],
            
            'pending_invoice' => $this->database_service->table($this->transaction)->whereBetween('transaction_date',$startDate, $endDate)
                              ->where('status', '=', 'Pending')->select(["SUM(amount) AS total_amount"])->first()['total_amount'],
        
        ];

        $st['net_profile'] = ($st['year_income'] ?? 0) - ($st['year_expense'] ?? 0);
        $st['year_q'] = $filter_year;
        return $st;
    }

    public function searchTransactions(array $filters): array {
        $queryBuilder = $this->database_service->table($this->transaction);

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $startDate = new DateTime($filters['start_date']);
            $endDate = new DateTime($filters['end_date']);

            $queryBuilder->whereBetween('transaction_date',$startDate->format('Y-m-d'), $endDate->format('Y-m-d'));

            unset($filters['start_date']);
            unset($filters['end_date']);
        }

        foreach($filters as $field=>$value) {
            if (!empty($value) && is_string($field)) {
                $queryBuilder->where($field, '=', $value);
            }
        }

        $queryBuilder->orderBy('transaction_date', 'DESC');

        return $queryBuilder->get();
    }

    public function getTransactionByEntityName(string $entity_name): ?array {
        return $this->database_service->table($this->transaction)->where('entity_name', '=', $entity_name)->first();
    }
    
}
