<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Order;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Payment
{
    protected DatabaseService $db;
    protected LoggerInterface $logger;
    protected ?int $orderId = null;

    public function __construct(DatabaseService $database, LoggerInterface $logger)
    {
        $this->db = $database;
        $this->logger = $logger;
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
     * Create a new payment
     */
    public function createPayment(array $data): int
    {
        $this->validatePaymentData($data);

        // Set defaults
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['status'] = $data['status'] ?? 'pending';
        $data['currency'] = $data['currency'] ?? 'USD';
        $data['payment_type'] = $data['payment_type'] ?? 'capture';

        // Handle JSON fields
        if (isset($data['gateway_response']) && is_array($data['gateway_response'])) {
            $data['gateway_response'] = json_encode($data['gateway_response']);
        }

        $sql = "INSERT INTO commerce_payment (
            order_id, payment_method, payment_gateway, transaction_id, amount, currency,
            status, payment_type, gateway_response, gateway_transaction_id,
            failure_reason, notes, created_at, updated_at
        ) VALUES (
            :order_id, :payment_method, :payment_gateway, :transaction_id, :amount, :currency,
            :status, :payment_type, :gateway_response, :gateway_transaction_id,
            :failure_reason, :notes, :created_at, :updated_at
        )";

        $this->db->query($sql, $data);
        $paymentId = $this->db->lastInsertId();
        
        $this->logger->info('Payment created', ['payment_id' => $paymentId, 'order_id' => $data['order_id'], 'amount' => $data['amount']]);
        
        return $paymentId;
    }

    /**
     * Get payment by ID
     */
    public function getPayment(int $paymentId): ?array
    {
        $sql = "SELECT * FROM commerce_payment WHERE id = ?";
        $payment = $this->db->fetch($sql, $paymentId);
        
        if ($payment && $payment['gateway_response']) {
            $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
        }
        
        return $payment;
    }

    /**
     * Get payments by order ID
     */
    public function getPaymentsByOrder(int $orderId): array
    {
        $sql = "SELECT * FROM commerce_payment WHERE order_id = ? ORDER BY created_at ASC";
        $payments = $this->db->fetchAll($sql, $orderId);
        
        // Decode JSON fields
        foreach ($payments as &$payment) {
            if ($payment['gateway_response']) {
                $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
            }
        }
        
        return $payments;
    }

    /**
     * Get payments by status
     */
    public function getPaymentsByStatus(string $status, int $limit = 50): array
    {
        $sql = "SELECT * FROM commerce_payment WHERE status = ? ORDER BY created_at DESC LIMIT ?";
        $payments = $this->db->fetchAll($sql, $status, $limit);
        
        // Decode JSON fields
        foreach ($payments as &$payment) {
            if ($payment['gateway_response']) {
                $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
            }
        }
        
        return $payments;
    }

    /**
     * Get payments by payment method
     */
    public function getPaymentsByMethod(string $paymentMethod, int $limit = 50): array
    {
        $sql = "SELECT * FROM commerce_payment WHERE payment_method = ? ORDER BY created_at DESC LIMIT ?";
        $payments = $this->db->fetchAll($sql, $paymentMethod, $limit);
        
        // Decode JSON fields
        foreach ($payments as &$payment) {
            if ($payment['gateway_response']) {
                $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
            }
        }
        
        return $payments;
    }

    /**
     * Update payment status
     */
    public function updatePaymentStatus(int $paymentId, string $status): bool
    {
        $validStatuses = ['pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled'];
        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $sql = "UPDATE commerce_payment SET status = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, $status, date('Y-m-d H:i:s'), $paymentId);
        
        $this->logger->info('Payment status updated', ['payment_id' => $paymentId, 'status' => $status]);
        
        return true;
    }

    /**
     * Process payment (mark as completed)
     */
    public function processPayment(int $paymentId, array $gatewayResponse = []): bool
    {
        $data = [
            'status' => 'completed',
            'processed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'gateway_response' => json_encode($gatewayResponse)
        ];

        $sql = "UPDATE commerce_payment SET status = ?, processed_at = ?, gateway_response = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, $data['status'], $data['processed_at'], $data['gateway_response'], $data['updated_at'], $paymentId);
        
        $this->logger->info('Payment processed', ['payment_id' => $paymentId, 'gateway_response' => $gatewayResponse]);
        
        return true;
    }

    /**
     * Fail payment
     */
    public function failPayment(int $paymentId, string $failureReason, array $gatewayResponse = []): bool
    {
        $data = [
            'status' => 'failed',
            'failure_reason' => $failureReason,
            'gateway_response' => json_encode($gatewayResponse),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $sql = "UPDATE commerce_payment SET status = ?, failure_reason = ?, gateway_response = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, $data['status'], $data['failure_reason'], $data['gateway_response'], $data['updated_at'], $paymentId);
        
        $this->logger->error('Payment failed', ['payment_id' => $paymentId, 'reason' => $failureReason]);
        
        return true;
    }

    /**
     * Refund payment
     */
    public function refundPayment(int $paymentId, float $refundAmount, string $reason = ''): bool
    {
        $payment = $this->getPayment($paymentId);
        if (!$payment) {
            throw new \InvalidArgumentException('Payment not found');
        }

        if ($refundAmount > $payment['amount']) {
            throw new \InvalidArgumentException('Refund amount cannot exceed payment amount');
        }

        $data = [
            'status' => $refundAmount >= $payment['amount'] ? 'refunded' : 'partially_refunded',
            'refunded_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'notes' => ($payment['notes'] ?? '') . "\nRefunded: {$reason} ({$refundAmount})"
        ];

        $sql = "UPDATE commerce_payment SET status = ?, refunded_at = ?, notes = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, $data['status'], $data['refunded_at'], $data['notes'], $data['updated_at'], $paymentId);
        
        $this->logger->info('Payment refunded', ['payment_id' => $paymentId, 'amount' => $refundAmount, 'reason' => $reason]);
        
        return true;
    }

    /**
     * Void payment
     */
    public function voidPayment(int $paymentId, string $reason = ''): bool
    {
        $data = [
            'status' => 'cancelled',
            'payment_type' => 'void',
            'updated_at' => date('Y-m-d H:i:s'),
            'notes' => ($this->getPayment($paymentId)['notes'] ?? '') . "\nVoided: {$reason}"
        ];

        $sql = "UPDATE commerce_payment SET status = ?, payment_type = ?, notes = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, $data['status'], $data['payment_type'], $data['notes'], $data['updated_at'], $paymentId);
        
        $this->logger->info('Payment voided', ['payment_id' => $paymentId, 'reason' => $reason]);
        
        return true;
    }

    /**
     * Get payment statistics
     */
    public function getPaymentStats(int $storeId, ?string $startDate = null, ?string $endDate = null): array
    {
        $whereClause = "WHERE p.order_id IN (SELECT id FROM commerce_orders WHERE store_id = ?)";
        $params = [$storeId];

        if ($startDate) {
            $whereClause .= " AND p.created_at >= ?";
            $params[] = $startDate;
        }

        if ($endDate) {
            $whereClause .= " AND p.created_at <= ?";
            $params[] = $endDate;
        }

        $sql = "SELECT 
            COUNT(*) as total_payments,
            SUM(p.amount) as total_amount,
            AVG(p.amount) as average_amount,
            COUNT(CASE WHEN p.status = 'completed' THEN 1 END) as successful_payments,
            COUNT(CASE WHEN p.status = 'failed' THEN 1 END) as failed_payments,
            COUNT(CASE WHEN p.status = 'refunded' THEN 1 END) as refunded_payments,
            SUM(CASE WHEN p.status = 'refunded' THEN p.amount ELSE 0 END) as total_refunded,
            COUNT(DISTINCT p.payment_method) as unique_methods
        FROM commerce_payment p {$whereClause}";

        return $this->db->fetch($sql, ...$params);
    }

    /**
     * Get payment method statistics
     */
    public function getPaymentMethodStats(int $storeId, ?string $startDate = null, ?string $endDate = null): array
    {
        $whereClause = "WHERE p.order_id IN (SELECT id FROM commerce_orders WHERE store_id = ?)";
        $params = [$storeId];

        if ($startDate) {
            $whereClause .= " AND p.created_at >= ?";
            $params[] = $startDate;
        }

        if ($endDate) {
            $whereClause .= " AND p.created_at <= ?";
            $params[] = $endDate;
        }

        $sql = "SELECT 
            p.payment_method,
            COUNT(*) as payment_count,
            SUM(p.amount) as total_amount,
            AVG(p.amount) as average_amount,
            COUNT(CASE WHEN p.status = 'completed' THEN 1 END) as successful_count,
            SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END) as successful_amount
        FROM commerce_payment p {$whereClause}
        GROUP BY p.payment_method
        ORDER BY total_amount DESC";

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Get daily payment revenue
     */
    public function getDailyPaymentRevenue(int $storeId, string $startDate, string $endDate): array
    {
        $sql = "SELECT 
            DATE(p.created_at) as date,
            COUNT(*) as payment_count,
            SUM(p.amount) as daily_revenue,
            COUNT(CASE WHEN p.status = 'completed' THEN 1 END) as successful_payments,
            SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END) as successful_revenue
        FROM commerce_payment p
        WHERE p.order_id IN (SELECT id FROM commerce_orders WHERE store_id = ?)
        AND p.created_at BETWEEN ? AND ?
        GROUP BY DATE(p.created_at)
        ORDER BY date DESC";
        
        return $this->db->fetchAll($sql, $storeId, $startDate, $endDate);
    }

    /**
     * Search payments
     */
    public function searchPayments(string $query, ?int $storeId = null, int $limit = 20): array
    {
        $sql = "SELECT p.* FROM commerce_payment p 
                 JOIN commerce_orders o ON p.order_id = o.id
                 WHERE (p.transaction_id LIKE ? OR p.gateway_transaction_id LIKE ? OR p.notes LIKE ?)";
        $params = ["%{$query}%", "%{$query}%", "%{$query}%"];

        if ($storeId) {
            $sql .= " AND o.store_id = ?";
            $params[] = $storeId;
        }

        $sql .= " ORDER BY p.created_at DESC LIMIT ?";
        $params[] = $limit;

        $payments = $this->db->fetchAll($sql, ...$params);
        
        // Decode JSON fields
        foreach ($payments as &$payment) {
            if ($payment['gateway_response']) {
                $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
            }
        }
        
        return $payments;
    }

    /**
     * Validate payment data
     */
    protected function validatePaymentData(array $data, bool $isUpdate = false): void
    {
        $required = ['order_id', 'payment_method', 'amount'];
        if (!$isUpdate) {
            $required[] = 'payment_type';
        }

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (isset($data['amount']) && $data['amount'] <= 0) {
            throw new \InvalidArgumentException("Amount must be positive");
        }

        if (isset($data['status'])) {
            $validStatuses = ['pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled'];
            if (!in_array($data['status'], $validStatuses)) {
                throw new \InvalidArgumentException("Invalid status: {$data['status']}");
            }
        }

        if (isset($data['payment_type'])) {
            $validTypes = ['authorization', 'capture', 'refund', 'void'];
            if (!in_array($data['payment_type'], $validTypes)) {
                throw new \InvalidArgumentException("Invalid payment type: {$data['payment_type']}");
            }
        }
    }
}
