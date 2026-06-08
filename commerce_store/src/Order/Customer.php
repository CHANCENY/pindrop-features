<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Order;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Customer
{
    protected DatabaseService $db;
    protected LoggerInterface $logger;
    protected ?int $customerId = null;

    public function __construct(DatabaseService $database, LoggerInterface $logger)
    {
        $this->db = $database;
        $this->logger = $logger;
    }

    /**
     * Set customer ID for subsequent operations
     */
    public function setCustomerId(int $customerId): self
    {
        $this->customerId = $customerId;
        return $this;
    }

    /**
     * Get customer ID
     */
    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    /**
     * Create a new customer
     */
    public function createCustomer(array $data): int
    {
        $this->validateCustomerData($data);

        // Set defaults
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['customer_type'] = $data['customer_type'] ?? 'guest';
        $data['total_orders'] = $data['total_orders'] ?? 0;
        $data['total_spent'] = $data['total_spent'] ?? 0;


        $customerId = $this->db->table('commerce_customer')->insert($data);
        
        $this->logger->info('Customer created', ['customer_id' => $customerId, 'email' => $data['email']]);
        
        return $customerId;
    }

    /**
     * Get customer by ID
     */
    public function getCustomer(int $customerId): ?array
    {
        return $this->db->table('commerce_customer')->where('id', '=', $customerId)->first();
    }

    /**
     * Get customer by email and store
     */
    public function getCustomerByEmail(string $email, int $storeId): ?array
    {
        return $this->db->table('commerce_customer')->where('email','=',$email)->where('store_id','=',$storeId)->first();
    }

    /**
     * Get customer by user ID
     * @throws DatabaseException
     */
    public function getCustomerByUser(int $userId): ?array
    {
        return $this->db->table('commerce_customer')->where('user_id','=',$userId)->first();
    }

    /**
     * Get customers by store ID
     */
    public function getCustomersByStore(int $storeId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->table('commerce_customer')
            ->where('store_id','=',$storeId)->latest()->limit($limit)->offset($offset)->get();
    }

    /**
     * Update customer information
     */
    public function updateCustomer(int $customerId, array $data): bool
    {
        $this->validateCustomerData($data, true);

        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['id'] = $customerId;

        $id = $data['id'];
        unset($data['id']);
        $this->db->table('commerce_customer')->where('id','=',$id)->update($data);
        
        $this->logger->info('Customer updated', ['customer_id' => $customerId]);
        
        return true;
    }

    /**
     * Update customer statistics
     */
    public function updateCustomerStats(int $customerId, array $stats): bool
    {
        $allowedFields = ['total_orders', 'total_spent', 'last_order_at'];
        $updateData = [];
        $params = [];

        foreach ($stats as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updateData[] = "{$field} = ?";
                $params[] = $value;
            }
        }

        if (empty($updateData)) {
            return false;
        }

        $updateRow = [];
        foreach ($stats as $f => $v) { if (in_array($f, $allowedFields)) $updateRow[$f] = $v; }
        $updateRow['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('commerce_customer')->where('id','=',$customerId)->update($updateRow);
        
        $this->logger->info('Customer stats updated', ['customer_id' => $customerId, 'stats' => $stats]);
        
        return true;
    }

    /**
     * Increment customer order statistics
     */
    public function incrementOrderStats(int $customerId, float $orderAmount): bool
    {
        $this->db->table('commerce_customer')->where('id','=',$customerId)
            ->whereRaw('1=1') // force QueryBuilder to allow raw increment
            ->update([
                'total_orders' => $this->db->table('commerce_customer')->where('id','=',$customerId)->value('total_orders') + 1,
                'total_spent'  => $this->db->table('commerce_customer')->where('id','=',$customerId)->value('total_spent') + $orderAmount,
                'last_order_at'=> date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        
        $this->logger->info('Customer order stats incremented', ['customer_id' => $customerId, 'amount' => $orderAmount]);
        
        return true;
    }

    /**
     * Decrement customer order statistics (for refunds/cancellations)
     */
    public function decrementOrderStats(int $customerId, float $orderAmount): bool
    {
        $curr = $this->db->table('commerce_customer')->where('id','=',$customerId)->first();
        $this->db->table('commerce_customer')->where('id','=',$customerId)->update([
            'total_orders' => max(0, ($curr['total_orders'] ?? 0) - 1),
            'total_spent'  => max(0, ($curr['total_spent']  ?? 0) - $orderAmount),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        
        $this->logger->info('Customer order stats decremented', ['customer_id' => $customerId, 'amount' => $orderAmount]);
        
        return true;
    }

    /**
     * Get customer statistics
     */
    public function getCustomerStats(int $storeId, ?string $startDate = null, ?string $endDate = null): array
    {
        $whereClause = "WHERE store_id = ?";
        $params = [$storeId];

        if ($startDate) {
            $whereClause .= " AND created_at >= ?";
            $params[] = $startDate;
        }

        if ($endDate) {
            $whereClause .= " AND created_at <= ?";
            $params[] = $endDate;
        }

        $qb = $this->db->table('commerce_customer')
            ->select(["COUNT(*) as total_customers",
                "COUNT(CASE WHEN customer_type = 'registered' THEN 1 END) as registered_customers",
                "COUNT(CASE WHEN customer_type = 'guest' THEN 1 END) as guest_customers",
                "SUM(total_orders) as total_orders","AVG(total_spent) as average_spent",
                "SUM(total_spent) as total_revenue",
                "COUNT(CASE WHEN last_order_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as active_customers",
            ])->where('store_id','=',$storeId);
        if ($startDate) $qb->where('created_at','>=',$startDate);
        if ($endDate)   $qb->where('created_at','<=',$endDate);
        return $qb->first();
    }

    /**
     * Search customers
     */
    public function searchCustomers(string $query, ?int $storeId = null, int $limit = 20): array
    {
        $qb = $this->db->table('commerce_customer')
            ->whereRaw("(email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR company LIKE ?)",
                ["%{$query}%","%{$query}%","%{$query}%","%{$query}%"])
            ->latest()->limit($limit);
        if ($storeId) $qb->where('store_id','=',$storeId);
        return $qb->get();
    }

    /**
     * Get top customers by spending
     */
    public function getTopCustomersBySpending(int $storeId, int $limit = 10): array
    {
        return $this->db->table('commerce_customer')
            ->where('store_id','=',$storeId)->where('total_spent','>',0)
            ->orderBy('total_spent','DESC')->limit($limit)->get();
    }

    /**
     * Get top customers by order count
     */
    public function getTopCustomersByOrders(int $storeId, int $limit = 10): array
    {
        return $this->db->table('commerce_customer')
            ->where('store_id','=',$storeId)->where('total_orders','>',0)
            ->orderBy('total_orders','DESC')->limit($limit)->get();
    }

    /**
     * Convert guest customer to registered
     */
    public function convertGuestToRegistered(int $customerId, int $userId): bool
    {
        $this->db->table('commerce_customer')
            ->where('id','=',$customerId)->where('customer_type','=','guest')
            ->update(['customer_type'=>'registered','user_id'=>$userId,'updated_at'=>date('Y-m-d H:i:s')]);
        
        $this->logger->info('Guest customer converted to registered', ['customer_id' => $customerId, 'user_id' => $userId]);
        
        return true;
    }

    /**
     * Validate customer data
     */
    protected function validateCustomerData(array $data, bool $isUpdate = false): void
    {
        $required = ['store_id', 'email'];
        if (!$isUpdate) {
            $required[] = 'customer_type';
        }

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email format");
        }

        if (isset($data['customer_type'])) {
            $validTypes = ['guest', 'registered'];
            if (!in_array($data['customer_type'], $validTypes)) {
                throw new \InvalidArgumentException("Invalid customer type: {$data['customer_type']}");
            }
        }

        if (isset($data['total_spent']) && $data['total_spent'] < 0) {
            throw new \InvalidArgumentException("Total spent cannot be negative");
        }

        if (isset($data['total_orders']) && $data['total_orders'] < 0) {
            throw new \InvalidArgumentException("Total orders cannot be negative");
        }
    }
}
