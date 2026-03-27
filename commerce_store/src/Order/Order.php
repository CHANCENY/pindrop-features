<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Order;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Order
{
    protected DatabaseService $db;
    protected LoggerInterface $logger;
    protected ?int $orderId = null;

    public function __construct(DatabaseService $database, LoggerInterface $logger)
    {
        $this->db = $database;
        $this->logger = $logger;
    }

    public function getDb()
    {
        return $this->db;
    }

    /**
     * Set order ID for subsequent operations
     */
    public function setOrderId(int $orderId): self
    {
        $this->orderId = $orderId;
        return $this;
    }

    /**
     * Get order ID
     */
    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    /**
     * Get order by ID
     */
    public function getOrder(int $orderId): ?array
    {
        $sql = "SELECT * FROM commerce_orders WHERE id = ?";
        $order = $this->db->fetch($sql, $orderId);
        
        if ($order && $order['adjustments']) {
            $order['adjustments'] = json_decode($order['adjustments'], true);
        }
        
        return $order;
    }

    /**
     * Get order by order number
     */
    public function getOrderByNumber(string $orderNumber): ?array
    {
        $sql = "SELECT * FROM commerce_orders WHERE order_number = ?";
        $order = $this->db->fetch($sql, $orderNumber);
        
        if ($order && $order['adjustments']) {
            $order['adjustments'] = json_decode($order['adjustments'], true);
        }
        
        return $order;
    }

    /**
     * Get orders by customer ID
     */
    public function getOrdersByCustomer(int $customerId, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM commerce_orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $orders = $this->db->fetchAll($sql, $customerId, $limit, $offset);
        
        foreach ($orders as &$order) {
            if ($order['adjustments']) {
                $order['adjustments'] = json_decode($order['adjustments'], true);
            }
        }
        
        return $orders;
    }

    /**
     * Create new order
     */
    public function createOrder(array $data): int
    {
        $this->validateOrderData($data);
        
        // Generate order number if not provided
        if (empty($data['order_number'])) {
            $data['order_number'] = 'ORD-' . uniqid();
        }
        
        // Set defaults
        $data['status'] = $data['status'] ?? 'pending';
        $data['payment_status'] = $data['payment_status'] ?? 'pending';
        $data['currency'] = $data['currency'] ?? 'USD';
        $data['subtotal'] = !empty($data['subtotal']) ? $data['subtotal'] : 0;
        $data['tax_amount'] = !empty( $data['tax_amount']) ?  $data['tax_amount'] : 0;
        $data['shipping_amount'] = !empty($data['shipping_amount']) ? $data['shipping_amount'] : 0;
        $data['discount_amount'] = $data['discount_amount'] ?? 0;
        $data['total_amount'] = $data['total_amount'] ?? 0;
        $data['refund_amount'] = $data['refund_amount'] ?? 0;
        $data['notes'] = $data['notes'] ?? '';
        $data['admin_notes'] = $data['admin_notes'] ?? '';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        // Serialize adjustments if provided
        if (isset($data['adjustments']) && is_array($data['adjustments'])) {
            $data['adjustments'] = serialize($data['adjustments']);
        } else {
            $data['adjustments'] = null;
        }

        $sql = "INSERT INTO commerce_orders (
            store_id, customer_id, order_number, status, payment_status,
            subtotal, tax_amount, shipping_amount, discount_amount, total_amount,
            refund_amount, currency, notes, admin_notes, adjustments,
            created_at, updated_at
        ) VALUES (
            :store_id, :customer_id, :order_number, :status, :payment_status,
            :subtotal, :tax_amount, :shipping_amount, :discount_amount, :total_amount,
            :refund_amount, :currency, :notes, :admin_notes, :adjustments,
            :created_at, :updated_at
        )";
        
        $this->db->query($sql, ...$data);
        
        $orderId = $this->db->lastInsertId();
        
        $this->logger->info('Order created', [
            'order_id' => $orderId,
            'order_number' => $data['order_number'],
            'customer_id' => $data['customer_id'],
            'total_amount' => $data['total_amount']
        ]);
        
        return $orderId;
    }

    /**
     * Get orders by store ID
     */
    public function getOrdersByStore(int $storeId, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM commerce_orders WHERE store_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $orders = $this->db->fetchAll($sql, $storeId, $limit, $offset);
        
        foreach ($orders as &$order) {
            if ($order['adjustments']) {
                $order['adjustments'] = json_decode($order['adjustments'], true);
            }
        }
        
        return $orders;
    }

    public function searchByFields(array $fields, int $limit = 50, int $offset = 0, string $extraWhereConnector = "AND", string $extraWhereClause = ""): array
    {
        $columns = array_keys($fields);

        $sql = "SELECT * FROM commerce_orders WHERE ";
        $placeholders = array_map(function ($field) {
            return "$field = :$field";
        }, $columns);
        $sql .= implode(" AND ", $placeholders);

        if (!empty($extraWhereClause) && !empty($extraWhereConnector)) {
            $sql .= " $extraWhereConnector $extraWhereClause";
        }

        $sql .= " ORDER BY created_at DESC LIMIT :l OFFSET :o";
        $fields['l'] = $limit;
        $fields['o'] = $offset;

        $orders = $this->db->fetchAll($sql, ...$fields);
        foreach ($orders as &$order) {
            if ($order['adjustments']) {
                $order['adjustments'] = json_decode($order['adjustments'], true);
            }
        }
        return $orders;
    }

    /**
     * Update order status
     */
    public function updateOrderStatus(int $orderId, string $status): bool
    {
        $validStatuses = ['pending', 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'on_hold'];
        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $sql = "UPDATE commerce_orders SET status = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, $status, date('Y-m-d H:i:s'), $orderId);
        
        $this->logger->info('Order status updated', ['order_id' => $orderId, 'status' => $status]);
        
        return true;
    }

    /**
     * Update payment status
     */
    public function updatePaymentStatus(int $orderId, string $paymentStatus): bool
    {
        $validStatuses = ['pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded'];
        if (!in_array($paymentStatus, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid payment status: {$paymentStatus}");
        }

        $sql = "UPDATE commerce_orders SET payment_status = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, $paymentStatus, date('Y-m-d H:i:s'), $orderId);
        
        $this->logger->info('Payment status updated', ['order_id' => $orderId, 'payment_status' => $paymentStatus]);
        
        return true;
    }

    /**
     * Update order totals
     */
    public function updateOrderTotals(int $orderId, array $totals): bool
    {
        $allowedFields = ['subtotal', 'tax_amount', 'shipping_amount', 'discount_amount', 'total_amount', 'refund_amount'];
        $updateData = [];
        $params = [];

        foreach ($totals as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updateData[] = "{$field} = ?";
                $params[] = $value;
            }
        }

        if (empty($updateData)) {
            return false;
        }

        $sql = "UPDATE commerce_orders SET " . implode(', ', $updateData) . ", updated_at = ? WHERE id = ?";
        $params[] = date('Y-m-d H:i:s');
        $params[] = $orderId;

        $this->db->query($sql, ...$params);
        
        $this->logger->info('Order totals updated', ['order_id' => $orderId, 'totals' => $totals]);
        return true;
    }

    /**
     * Update order
     * @throws DatabaseException
     */
    public function updateOrder(int $orderId, array $data): bool
    {
        $this->validateOrderData($data);

        // Build update data
        $updateData = [];
        $params = [];

        $allowedFields = [
            'customer_id',
            'order_number',
            'status',
            'payment_status',
            'subtotal',
            'tax_amount',
            'shipping_amount',
            'discount_amount',
            'total_amount',
            'refund_amount',
            'currency',
            'notes',
            'admin_notes',
        ];

        // Build SET clauses
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $updateData[] = "{$field} = :$field";
                $params[$field] = $value;
            }
        }

       // Always update adjustments if present
        if (array_key_exists('adjustments', $data)) {
            $updateData[] = "adjustments = :adjustments";
            $params['adjustments'] = json_encode($data['adjustments']);
        }

        // Nothing to update
        if (empty($updateData)) {
            return false;
        }

        // Always update timestamp
        $updateData[] = "updated_at = :updated_at";
        $params['updated_at'] = date('Y-m-d H:i:s');

        // Final SQL
        $sql = "UPDATE commerce_orders SET " . implode(', ', $updateData) . " WHERE id = :order_id";
        $params['order_id'] = $orderId;

        // Execute query with named parameters
        $this->db->query($sql, ...$params);
        
        // Handle order items if provided
        if (isset($data['items']) && is_array($data['items'])) {
            $orderItem = new OrderItem($this->db, $this->logger);
            $orderItem->deleteItems($orderId);

            foreach ($data['items'] as $item) {
                $orderItem->addOrderItem($orderId, $item);
            }
        }
        
        $this->logger->info('Order updated', ['order_id' => $orderId, 'data' => $data]);
        return true;
    }

    /**
     * Add adjustment to order
     */
    public function addAdjustment(int $orderId, array $adjustment): bool
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Order not found');
        }

        $adjustments = $order['adjustments'] ?? [];
        $adjustments[] = $adjustment;

        $sql = "UPDATE commerce_orders SET adjustments = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, serialize($adjustments), date('Y-m-d H:i:s'), $orderId);
        
        $this->logger->info('Adjustment added', ['order_id' => $orderId, 'adjustment' => $adjustment]);
        
        return true;
    }

    /**
     * Cancel order
     */
    public function cancelOrder(int $orderId, string $reason = ''): bool
    {
        $this->updateOrderStatus($orderId, 'cancelled');
        
        if ($reason) {
            $sql = "UPDATE commerce_orders SET admin_notes = CONCAT(IFNULL(admin_notes, ''), '\nCancelled: ', ?), updated_at = ? WHERE id = ?";
            $this->db->query($sql, $reason, date('Y-m-d H:i:s'), $orderId);
        }
        
        $this->logger->info('Order cancelled', ['order_id' => $orderId, 'reason' => $reason]);
        
        return true;
    }

    /**
     * Complete order
     */
    public function completeOrder(int $orderId): bool
    {
        $sql = "UPDATE commerce_orders SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $orderId);
        
        $this->logger->info('Order completed', ['order_id' => $orderId]);
        
        return true;
    }

    /**
     * Get order statistics
     */
    public function getOrderStats(int $storeId, ?string $startDate = null, ?string $endDate = null): array
    {
        $whereClause = "WHERE store_id = ?";
        $params = [$storeId];

        if ($startDate && $endDate) {
            $whereClause .= " AND created_at BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
        }

        $sql = "SELECT 
            COUNT(*) as total_orders,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as average_order_value,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders,
            COUNT(CASE WHEN payment_status = 'completed' THEN 1 END) as paid_orders,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders,
            SUM(refund_amount) as total_refunds
        FROM commerce_orders {$whereClause}";

        return $this->db->fetch($sql, ...$params);
    }

    /**
     * Search orders
     */
    public function searchOrders(string $query, ?int $storeId = null, int $limit = 20): array
    {
        $sql = "SELECT o.* FROM commerce_orders o 
                 LEFT JOIN commerce_customer c ON o.customer_id = c.id
                 WHERE (o.order_number LIKE ? OR c.email LIKE ? OR o.notes LIKE ?)";
        $params = ["%{$query}%", "%{$query}%", "%{$query}%"];

        if ($storeId) {
            $sql .= " AND o.store_id = ?";
            $params[] = $storeId;
        }

        $sql .= " ORDER BY o.created_at DESC LIMIT ?";
        $params[] = $limit;

        $orders = $this->db->fetchAll($sql, ...$params);
        
        foreach ($orders as &$order) {
            if ($order['adjustments']) {
                $order['adjustments'] = json_decode($order['adjustments'], true);
            }
        }
        
        return $orders;
    }

    /**
     * Validate order data
     */
    protected function validateOrderData(array $data): void
    {
        $required = ['store_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (isset($data['total_amount']) && $data['total_amount'] < 0) {
            throw new \InvalidArgumentException("Total amount cannot be negative");
        }

        if (isset($data['currency']) && !is_string($data['currency'])) {
            throw new \InvalidArgumentException("Currency must be a string");
        }

        if (isset($data['status'])) {
            $validStatuses = ['pending', 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'on_hold'];
            if (!in_array($data['status'], $validStatuses)) {
                throw new \InvalidArgumentException("Invalid status: {$data['status']}");
            }
        }
    }
}
