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


        $sql = "INSERT INTO commerce_customer (
            store_id, user_id, first_name, customer_type, last_name, email, phone, company, billing_address_1, billing_address_2, billing_city, billing_state,
            billing_postcode, billing_country, shipping_same_as_billing, shipping_address_1,
            shipping_address_2, shipping_city, shipping_state, shipping_postcode,
            shipping_country, total_orders, total_spent, created_at, updated_at
        ) VALUES (
            :store_id, :user_id, :first_name, :customer_type, :last_name, :email, :phone, :company,
            :billing_address_1, :billing_address_2, :billing_city, :billing_state,
            :billing_postcode, :billing_country, :shipping_same_as_billing, :shipping_address_1,
            :shipping_address_2, :shipping_city, :shipping_state, :shipping_postcode,
            :shipping_country, :total_orders, :total_spent, :created_at, :updated_at
        )";

        $this->db->query($sql, ...$data);
        $customerId = $this->db->lastInsertId();
        
        $this->logger->info('Customer created', ['customer_id' => $customerId, 'email' => $data['email']]);
        
        return $customerId;
    }

    /**
     * Get customer by ID
     */
    public function getCustomer(int $customerId): ?array
    {
        $sql = "SELECT * FROM commerce_customer WHERE id = ?";
        return $this->db->fetch($sql, $customerId);
    }

    /**
     * Get customer by email and store
     */
    public function getCustomerByEmail(string $email, int $storeId): ?array
    {
        $sql = "SELECT * FROM commerce_customer WHERE email = ? AND store_id = ?";
        return $this->db->fetch($sql, $email, $storeId);
    }

    /**
     * Get customer by user ID
     * @throws DatabaseException
     */
    public function getCustomerByUser(int $userId): ?array
    {
        $sql = "SELECT * FROM commerce_customer WHERE user_id = ?";
        return $this->db->fetch($sql, $userId);
    }

    /**
     * Get customers by store ID
     */
    public function getCustomersByStore(int $storeId, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM commerce_customer WHERE store_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
        return $this->db->fetchAll($sql, $storeId, $limit, $offset);
    }

    /**
     * Update customer information
     */
    public function updateCustomer(int $customerId, array $data): bool
    {
        $this->validateCustomerData($data, true);

        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['id'] = $customerId;

        $sql = "UPDATE commerce_customer SET 
            user_id = :user_id, store_id = :store_id, customer_type = :customer_type,
            email = :email, phone = :phone, first_name = :first_name, last_name = :last_name,
            company = :company, billing_address_1 = :billing_address_1, billing_address_2 = :billing_address_2,
            billing_city = :billing_city, billing_state = :billing_state, billing_postcode = :billing_postcode,
            billing_country = :billing_country, shipping_same_as_billing = :shipping_same_as_billing,
            shipping_address_1 = :shipping_address_1, shipping_address_2 = :shipping_address_2,
            shipping_city = :shipping_city, shipping_state = :shipping_state, shipping_postcode = :shipping_postcode,
            shipping_country = :shipping_country, updated_at = :updated_at
        WHERE id = :id";

        $this->db->query($sql, ...$data);
        
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

        $sql = "UPDATE commerce_customer SET " . implode(', ', $updateData) . ", updated_at = ? WHERE id = ?";
        $params[] = date('Y-m-d H:i:s');
        $params[] = $customerId;

        $this->db->query($sql, ...$params);
        
        $this->logger->info('Customer stats updated', ['customer_id' => $customerId, 'stats' => $stats]);
        
        return true;
    }

    /**
     * Increment customer order statistics
     */
    public function incrementOrderStats(int $customerId, float $orderAmount): bool
    {
        $sql = "UPDATE commerce_customer SET 
            total_orders = total_orders + 1,
            total_spent = total_spent + ?,
            last_order_at = ?,
            updated_at = ?
        WHERE id = ?";

        $this->db->query($sql, $orderAmount, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $customerId);
        
        $this->logger->info('Customer order stats incremented', ['customer_id' => $customerId, 'amount' => $orderAmount]);
        
        return true;
    }

    /**
     * Decrement customer order statistics (for refunds/cancellations)
     */
    public function decrementOrderStats(int $customerId, float $orderAmount): bool
    {
        $sql = "UPDATE commerce_customer SET 
            total_orders = GREATEST(total_orders - 1, 0),
            total_spent = GREATEST(total_spent - ?, 0),
            updated_at = ?
        WHERE id = ?";

        $this->db->query($sql, $orderAmount, date('Y-m-d H:i:s'), $customerId);
        
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

        $sql = "SELECT 
            COUNT(*) as total_customers,
            COUNT(CASE WHEN customer_type = 'registered' THEN 1 END) as registered_customers,
            COUNT(CASE WHEN customer_type = 'guest' THEN 1 END) as guest_customers,
            SUM(total_orders) as total_orders,
            AVG(total_spent) as average_spent,
            SUM(total_spent) as total_revenue,
            COUNT(CASE WHEN last_order_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as active_customers
        FROM commerce_customer {$whereClause}";

        return $this->db->fetch($sql, ...$params);
    }

    /**
     * Search customers
     */
    public function searchCustomers(string $query, ?int $storeId = null, int $limit = 20): array
    {
        $sql = "SELECT * FROM commerce_customer WHERE 
                (email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR company LIKE ?)";
        $params = ["%{$query}%", "%{$query}%", "%{$query}%", "%{$query}%"];

        if ($storeId) {
            $sql .= " AND store_id = ?";
            $params[] = $storeId;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Get top customers by spending
     */
    public function getTopCustomersBySpending(int $storeId, int $limit = 10): array
    {
        $sql = "SELECT * FROM commerce_customer 
                 WHERE store_id = ? AND total_spent > 0
                 ORDER BY total_spent DESC 
                 LIMIT ?";
        
        return $this->db->fetchAll($sql, $storeId, $limit);
    }

    /**
     * Get top customers by order count
     */
    public function getTopCustomersByOrders(int $storeId, int $limit = 10): array
    {
        $sql = "SELECT * FROM commerce_customer 
                 WHERE store_id = ? AND total_orders > 0
                 ORDER BY total_orders DESC 
                 LIMIT ?";
        
        return $this->db->fetchAll($sql, $storeId, $limit);
    }

    /**
     * Convert guest customer to registered
     */
    public function convertGuestToRegistered(int $customerId, int $userId): bool
    {
        $sql = "UPDATE commerce_customer SET 
            customer_type = 'registered',
            user_id = ?,
            updated_at = ?
        WHERE id = ? AND customer_type = 'guest'";

        $this->db->query($sql, $userId, date('Y-m-d H:i:s'), $customerId);
        
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
