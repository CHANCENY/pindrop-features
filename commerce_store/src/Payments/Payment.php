<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Payments;

use Simp\Pindrop\Database\DatabaseException;
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

        $sql = "INSERT INTO commerce_payment (
            order_id, payment_method, payment_gateway, transaction_id, 
            amount, currency, status, payment_type, gateway_response, 
            gateway_transaction_id, failure_reason, notes
        ) VALUES (
            :order_id, :payment_method, :payment_gateway, :transaction_id,
            :amount, :currency, :status, :payment_type, :gateway_response,
            :gateway_transaction_id, :failure_reason, :notes
        )";

        $params = [
            'order_id' => $data['order_id'],
            'payment_method' => $data['payment_method'],
            'payment_gateway' => $data['payment_gateway'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'status' => $data['status'] ?? 'pending',
            'payment_type' => $data['payment_type'] ?? 'capture',
            'gateway_response' => $data['gateway_response'] ?? null,
            'gateway_transaction_id' => $data['gateway_transaction_id'] ?? null,
            'failure_reason' => $data['failure_reason'] ?? null,
            'notes' => $data['notes'] ?? null
        ];

        $this->db->query($sql, ...$params);
        $paymentId = $this->db->lastInsertId();

        $this->logger->info('Payment created', [
            'payment_id' => $paymentId,
            'order_id' => $data['order_id'],
            'amount' => $data['amount'],
            'method' => $data['payment_method']
        ]);

        return $paymentId;
    }

    /**
     * Get payment by ID
     * @throws DatabaseException
     */
    public function getPayment(int $paymentId): ?array
    {
        $sql = "SELECT * FROM commerce_payment WHERE id = ?";
        $payment = $this->db->fetch($sql, $paymentId);
        
        if ($payment && isset($payment['gateway_response'])) {
            $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
        }
        
        return $payment;
    }

    /**
     * Get payments by order ID
     * @throws DatabaseException
     */
    public function getPaymentsByOrder(int $orderId): array
    {
        $sql = "SELECT * FROM commerce_payment WHERE order_id = ? ORDER BY created_at ASC";
        $payments = $this->db->fetchAll($sql, $orderId);
        
        foreach ($payments as &$payment) {
            if (isset($payment['gateway_response'])) {
                $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
            }
        }
        
        return $payments;
    }

    /**
     * Get payments by status
     * @throws DatabaseException
     */
    public function getPaymentsByStatus(string $status, int $limit = 50): array
    {
        $sql = "SELECT * FROM commerce_payment WHERE status = ? ORDER BY created_at DESC LIMIT ?";
        $payments = $this->db->fetchAll($sql, $status, $limit);
        
        foreach ($payments as &$payment) {
            if (isset($payment['gateway_response'])) {
                $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
            }
        }
        
        return $payments;
    }

    /**
     * Get payments by payment method
     * @throws DatabaseException
     */
    public function getPaymentsByMethod(string $paymentMethod, int $limit = 50): array
    {
        $sql = "SELECT * FROM commerce_payment WHERE payment_method = ? ORDER BY created_at DESC LIMIT ?";
        $payments = $this->db->fetchAll($sql, $paymentMethod, $limit);
        
        foreach ($payments as &$payment) {
            if (isset($payment['gateway_response'])) {
                $payment['gateway_response'] = json_decode($payment['gateway_response'], true);
            }
        }
        
        return $payments;
    }

    /**
     * Update payment status
     * @throws DatabaseException
     */
    public function updatePaymentStatus(int $paymentId, string $status): bool
    {
        $validStatuses = ['pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled'];
        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid payment status: {$status}");
        }

        $sql = "UPDATE commerce_payment SET status = :status, updated_at = NOW() WHERE id = :id";
        $params = [
            'status' => $status,
            'id' => $paymentId
        ];

        $result = $this->db->query($sql, ...$params);
        
        $this->logger->info('Payment status updated', [
            'payment_id' => $paymentId,
            'new_status' => $status
        ]);
        
        return $result->rowCount() > 0;
    }

    /**
     * Process payment (mark as completed)
     */
    public function processPayment(int $paymentId, array $gatewayResponse = []): bool
    {
        $data = [
            'status' => 'completed',
            'processed_at' => date('Y-m-d H:i:s'),
            'gateway_response' => json_encode($gatewayResponse)
        ];

        return $this->updatePayment($paymentId, $data);
    }

    /**
     * Fail payment
     */
    public function failPayment(int $paymentId, string $failureReason, array $gatewayResponse = []): bool
    {
        $data = [
            'status' => 'failed',
            'failure_reason' => $failureReason,
            'gateway_response' => json_encode($gatewayResponse)
        ];

        return $this->updatePayment($paymentId, $data);
    }

    /**
     * Refund payment
     */
    public function refundPayment(int $paymentId, float $refundAmount, string $reason = ''): bool
    {
        $payment = $this->getPayment($paymentId);
        if (!$payment) {
            throw new \InvalidArgumentException("Payment not found: {$paymentId}");
        }

        if ($refundAmount > $payment['amount']) {
            throw new \InvalidArgumentException("Refund amount cannot exceed payment amount");
        }

        // Check if this is a partial refund
        $isPartialRefund = $refundAmount < $payment['amount'];
        $status = $isPartialRefund ? 'partially_refunded' : 'refunded';

        $data = [
            'status' => $status,
            'refunded_at' => date('Y-m-d H:i:s'),
            'notes' => $reason
        ];

        return $this->updatePayment($paymentId, $data);
    }

    /**
     * Void payment
     */
    public function voidPayment(int $paymentId, string $reason = ''): bool
    {
        $data = [
            'status' => 'cancelled',
            'notes' => $reason
        ];

        return $this->updatePayment($paymentId, $data);
    }

    /**
     * Update payment fields
     */
    public function updatePayment(int $paymentId, array $data): bool
    {
        $allowedFields = [
            'payment_method', 'payment_gateway', 'transaction_id', 'amount', 
            'currency', 'status', 'payment_type', 'gateway_response',
            'gateway_transaction_id', 'failure_reason', 'notes', 'processed_at', 'refunded_at'
        ];

        $updateFields = [];
        $params = ['id' => $paymentId];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $updateFields[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
        }

        if (empty($updateFields)) {
            return false;
        }

        $updateFields[] = 'updated_at = NOW()';
        $sql = "UPDATE commerce_payment SET " . implode(', ', $updateFields) . " WHERE id = :id";

        $result = $this->db->query($sql, $params);
        
        $this->logger->info('Payment updated', [
            'payment_id' => $paymentId,
            'updated_fields' => array_keys($data)
        ]);
        
        return $result->rowCount() > 0;
    }

    /**
     * Delete payment
     * @throws DatabaseException
     */
    public function deletePayment(int $paymentId): bool
    {
        $sql = "DELETE FROM commerce_payment WHERE id = ?";
        $result = $this->db->query($sql, $paymentId);
        
        $this->logger->info('Payment deleted', ['payment_id' => $paymentId]);
        
        return $result->rowCount() > 0;
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
            SUM(amount) as total_amount,
            SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as completed_amount,
            SUM(CASE WHEN status = 'failed' THEN amount ELSE 0 END) as failed_amount,
            SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END) as refunded_amount,
            AVG(amount) as average_amount
            FROM commerce_payment p 
            {$whereClause}";

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
            payment_method,
            COUNT(*) as payment_count,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount
            FROM commerce_payment p 
            {$whereClause}
            GROUP BY payment_method
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
            SUM(CASE WHEN p.status = 'completed' THEN amount ELSE 0 END) as revenue,
            SUM(amount) as total_amount
            FROM commerce_payment p 
            WHERE p.order_id IN (SELECT id FROM commerce_orders WHERE store_id = ?)
            AND p.created_at BETWEEN ? AND ?
            GROUP BY DATE(p.created_at)
            ORDER BY date ASC";

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
        
        foreach ($payments as &$payment) {
            if (isset($payment['gateway_response'])) {
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
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new \InvalidArgumentException("Required field missing: {$field}");
                }
            }
        }

        if (isset($data['amount']) && (!is_numeric($data['amount']) || $data['amount'] <= 0)) {
            throw new \InvalidArgumentException("Payment amount must be a positive number");
        }

        if (isset($data['status'])) {
            $validStatuses = ['pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled'];
            if (!in_array($data['status'], $validStatuses)) {
                throw new \InvalidArgumentException("Invalid payment status: {$data['status']}");
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