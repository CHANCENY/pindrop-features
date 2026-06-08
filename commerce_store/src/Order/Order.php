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
        $order = $this->db->table('commerce_orders')
            ->where('id', '=', $orderId)
            ->first();
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
        $order = $this->db->table('commerce_orders')
            ->where('order_number', '=', $orderNumber)
            ->first();
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
        $orders = $this->db->table('commerce_orders')
            ->where('customer_id', '=', $customerId)
            ->latest('created_at')
            ->limit($limit)->offset($offset)
            ->get();
        foreach ($orders as &$order) {
            if ($order['adjustments']) $order['adjustments'] = json_decode($order['adjustments'], true);
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

        $orderId = $this->db->table('commerce_orders')->insert($data);
        
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
        $orders = $this->db->table('commerce_orders')
            ->where('store_id', '=', $storeId)
            ->latest('created_at')
            ->limit($limit)->offset($offset)
            ->get();
        foreach ($orders as &$order) {
            if ($order['adjustments']) $order['adjustments'] = json_decode($order['adjustments'], true);
        }
        return $orders;
    }

    public function searchByFields(array $fields, int $limit = 50, int $offset = 0, string $extraWhereConnector = "AND", string $extraWhereClause = ""): array
    {
        $qb = $this->db->table('commerce_orders')->latest('created_at')->limit($limit)->offset($offset);
        foreach ($fields as $col => $val) {
            $qb->where($col, '=', $val);
        }
        if (!empty($extraWhereClause)) {
            $qb->whereRaw($extraWhereClause);
        }
        $orders = $qb->get();
        foreach ($orders as &$order) {
            if ($order['adjustments']) $order['adjustments'] = json_decode($order['adjustments'], true);
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

        $this->db->table('commerce_orders')->where('id', '=', $orderId)
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
        
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

        $this->db->table('commerce_orders')->where('id', '=', $orderId)
            ->update(['payment_status' => $paymentStatus, 'updated_at' => date('Y-m-d H:i:s')]);
        
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

        $updateRow = [];
        foreach ($totals as $field => $value) {
            if (in_array($field, $allowedFields)) $updateRow[$field] = $value;
        }
        $updateRow['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('commerce_orders')->where('id', '=', $orderId)->update($updateRow);
        
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

        // Execute update via QueryBuilder
        $this->db->table('commerce_orders')->where('id', '=', $orderId)->update($params);
        
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

        $this->db->table('commerce_orders')->where('id', '=', $orderId)
            ->update(['adjustments' => json_encode($adjustments), 'updated_at' => date('Y-m-d H:i:s')]);
        
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
            $existing = $this->db->table('commerce_orders')->select(['admin_notes'])->where('id','=',$orderId)->value('admin_notes');
            $this->db->table('commerce_orders')->where('id', '=', $orderId)
                ->update(['admin_notes' => ($existing ?? '') . "
Cancelled: " . $reason, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        
        $this->logger->info('Order cancelled', ['order_id' => $orderId, 'reason' => $reason]);
        
        return true;
    }

    /**
     * Complete order
     */
    public function completeOrder(int $orderId): bool
    {
        $this->db->table('commerce_orders')->where('id', '=', $orderId)
            ->update(['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        
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

        $qb = $this->db->table('commerce_orders')
            ->select([
                'COUNT(*) as total_orders',
                'SUM(total_amount) as total_revenue',
                'AVG(total_amount) as average_order_value',
                "COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders",
                "COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders",
                "COUNT(CASE WHEN payment_status = 'completed' THEN 1 END) as paid_orders",
                "COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders",
                'SUM(refund_amount) as total_refunds',
            ])
            ->where('store_id', '=', $storeId);
        if ($startDate && $endDate) {
            $qb->whereBetween('created_at', $startDate, $endDate);
        }
        return $qb->first();
    }

    /**
     * Search orders
     */
    public function searchOrders(string $query, ?int $storeId = null, int $limit = 20): array
    {
        $qb = $this->db->table('commerce_orders')
            ->leftJoin('commerce_customer', 'commerce_orders.customer_id', '=', 'commerce_customer.id')
            ->whereRaw("(commerce_orders.order_number LIKE ? OR commerce_customer.email LIKE ? OR commerce_orders.notes LIKE ?)",
                ["%{$query}%", "%{$query}%", "%{$query}%"])
            ->latest('commerce_orders.created_at')
            ->limit($limit);
        if ($storeId) $qb->where('commerce_orders.store_id', '=', $storeId);
        $orders = $qb->get();
        
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
