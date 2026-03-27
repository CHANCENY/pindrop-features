<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Order;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;
use Simp\Pindrop\Modules\commerce_store\src\Shipment\ShipmentMethodManager;

class OrderManager
{
    protected DatabaseService $db;
    protected LoggerInterface $logger;
    protected Order $order;
    protected Customer $customer;
    protected OrderItem $orderItem;
    protected Payment $payment;
    protected OrderActivity $orderActivity;
    protected ShipmentMethodManager $shipmentMethodManager;

    public function __construct(DatabaseService $database, LoggerInterface $logger)
    {
        $this->db = $database;
        $this->logger = $logger;
        $this->order = new Order($database, $logger);
        $this->customer = new Customer($database, $logger);
        $this->orderItem = new OrderItem($database, $logger);
        $this->payment = new Payment($database, $logger);
        $this->orderActivity = new OrderActivity($database, $logger);
        $this->shipmentMethodManager = new ShipmentMethodManager();
    }

    /**
     * Get order service
     */
    public function order(): Order
    {
        return $this->order;
    }

    /**
     * Get customer service
     */
    public function customer(): Customer
    {
        return $this->customer;
    }

    /**
     * Get order item service
     */
    public function orderItem(): OrderItem
    {
        return $this->orderItem;
    }

    /**
     * Get payment service
     */
    public function payment(): Payment
    {
        return $this->payment;
    }

    /**
     * Get order activity service
     */
    public function orderActivity(): OrderActivity
    {
        return $this->orderActivity;
    }

    /**
     * Create complete order with items and payment
     */
    public function createCompleteOrder(array $orderData, array $items, array $paymentData = []): array
    {
        try {
            // Start transaction
            $this->db->query("START TRANSACTION");

            // Create or get customer
            $customerId = $orderData['customer_id'];
            if (!$customerId) {
                $customerData = [
                    'store_id' => $orderData['store_id'],
                    'email' => $orderData['customer_email'],
                    'first_name' => $orderData['customer_first_name'] ?? null,
                    'last_name' => $orderData['customer_last_name'] ?? null,
                    'customer_type' => 'guest'
                ];
                $customerId = $this->customer->createCustomer($customerData);
            }

            // Create order
            $orderData['customer_id'] = $customerId;
            $orderId = $this->order->createOrder($orderData);

            // Add order items
            $itemIds = [];
            foreach ($items as $item) {
                $itemId = $this->orderItem->addOrderItem($orderId, $item);
                $itemIds[] = $itemId;
            }

            // Create payment if provided
            $paymentId = null;
            if (!empty($paymentData)) {
                $paymentData['order_id'] = $orderId;
                $paymentId = $this->payment->createPayment($paymentData);
            }

            // Log order creation activity
            $this->orderActivity->addSystemEvent($orderId, 'Order created', [
                'item_count' => count($items),
                'payment_id' => $paymentId
            ]);

            // Commit transaction
            $this->db->query("COMMIT");

            $this->logger->info('Complete order created', [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'item_count' => count($items),
                'payment_id' => $paymentId
            ]);

            return [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'item_ids' => $itemIds,
                'payment_id' => $paymentId
            ];

        } catch (\Exception $e) {
            // Rollback on error
            $this->db->query("ROLLBACK");
            $this->logger->error('Order creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Process order payment
     */
    public function processOrderPayment(int $orderId, array $paymentData): bool
    {
        try {
            // Start transaction
            $this->db->query("START TRANSACTION");

            // Create payment
            $paymentData['order_id'] = $orderId;
            $paymentId = $this->payment->createPayment($paymentData);

            // Update order payment status
            $this->order->updatePaymentStatus($orderId, 'processing');

            // Log payment activity
            $this->orderActivity->addPaymentUpdate($orderId, 'processing', null, 'Payment initiated');

            // Commit transaction
            $this->db->query("COMMIT");

            $this->logger->info('Order payment processed', [
                'order_id' => $orderId,
                'payment_id' => $paymentId
            ]);

            return true;

        } catch (\Exception $e) {
            // Rollback on error
            $this->db->query("ROLLBACK");
            $this->logger->error('Payment processing failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Complete order with payment
     */
    public function completeOrderWithPayment(int $orderId, array $gatewayResponse = []): bool
    {
        try {
            // Start transaction
            $this->db->query("START TRANSACTION");

            // Get order details
            $order = $this->order->getOrder($orderId);
            if (!$order) {
                throw new \InvalidArgumentException('Order not found');
            }

            // Get payments
            $payments = $this->payment->getPaymentsByOrder($orderId);
            
            // Complete successful payments
            foreach ($payments as $payment) {
                if ($payment['status'] === 'processing') {
                    $this->payment->processPayment($payment['id'], $gatewayResponse);
                }
            }

            // Update order status
            $this->order->completeOrder($orderId);

            // Update customer statistics
            $this->customer->incrementOrderStats($order['customer_id'], $order['total_amount']);

            // Log completion activity
            $this->orderActivity->addStatusChange($orderId, $order['status'], 'completed');
            $this->orderActivity->addSystemEvent($orderId, 'Order completed', [
                'payment_count' => count($payments),
                'total_amount' => $order['total_amount']
            ]);

            // Commit transaction
            $this->db->query("COMMIT");

            $this->logger->info('Order completed with payment', ['order_id' => $orderId]);

            return true;

        } catch (\Exception $e) {
            // Rollback on error
            $this->db->query("ROLLBACK");
            $this->logger->error('Order completion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Cancel order with refund
     */
    public function cancelOrderWithRefund(int $orderId, string $reason = ''): bool
    {
        try {
            // Start transaction
            $this->db->query("START TRANSACTION");

            // Get order details
            $order = $this->order->getOrder($orderId);
            if (!$order) {
                throw new \InvalidArgumentException('Order not found');
            }

            // Cancel order
            $this->order->cancelOrder($orderId, $reason);

            // Refund payments if any
            $payments = $this->payment->getPaymentsByOrder($orderId);
            foreach ($payments as $payment) {
                if (in_array($payment['status'], ['completed', 'processing'])) {
                    $this->payment->refundPayment($payment['id'], $payment['amount'], 'Order cancelled: ' . $reason);
                }
            }

            // Update customer statistics
            $this->customer->decrementOrderStats($order['customer_id'], $order['total_amount']);

            // Log cancellation activity
            $this->orderActivity->addStatusChange($orderId, $order['status'], 'cancelled', null, $reason);
            $this->orderActivity->addSystemEvent($orderId, 'Order cancelled', [
                'reason' => $reason,
                'refunded_payments' => count($payments)
            ]);

            // Commit transaction
            $this->db->query("COMMIT");

            $this->logger->info('Order cancelled with refund', ['order_id' => $orderId, 'reason' => $reason]);

            return true;

        } catch (\Exception $e) {
            // Rollback on error
            $this->db->query("ROLLBACK");
            $this->logger->error('Order cancellation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get complete order information
     */
    public function getCompleteOrder(int $orderId): ?array
    {
        // Get order
        $order = $this->order->getOrder($orderId);
        if (!$order) {
            return null;
        }

        // Get customer
        $customer = $this->customer->getCustomer($order['customer_id']);

        // Get order items
        $items = $this->orderItem->getOrderItems($orderId);

        // Get payments
        $payments = $this->payment->getPaymentsByOrder($orderId);

        // Get activities
        $activities = $this->orderActivity->getActivityTimeline($orderId);

        return [
            'order' => $order,
            'customer' => $customer,
            'items' => $items,
            'payments' => $payments,
            'activities' => $activities
        ];
    }

    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(int $storeId, int $days = 30): array
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = new \DateTime("now + 1 day");
        $endDate = $endDate->format('Y-m-d');

        return [
            'orders' => $this->order->getOrderStats($storeId, $startDate, $endDate),
            'customers' => $this->customer->getCustomerStats($storeId, $startDate, $endDate),
            'items' => $this->orderItem->getItemStats($storeId, $startDate, $endDate),
            'payments' => $this->payment->getPaymentStats($storeId, $startDate, $endDate),
            'payment_methods' => $this->payment->getPaymentMethodStats($storeId, $startDate, $endDate),
            'activities' => $this->orderActivity->getActivityStats($storeId, $startDate, $endDate)
        ];
    }

    /**
     * Get revenue analytics
     */
    public function getRevenueAnalytics(int $storeId, string $startDate, string $endDate): array
    {
        return [
            'daily_revenue' => $this->payment->getDailyPaymentRevenue($storeId, $startDate, $endDate),
            'item_revenue' => $this->orderItem->getItemRevenueByDate($storeId, $startDate, $endDate),
            'top_products' => $this->orderItem->getTopSellingProducts($storeId, 10),
            'top_customers' => $this->customer->getTopCustomersBySpending($storeId, 10)
        ];
    }

    /**
     * Search orders with complete information
     */
    public function searchCompleteOrders(string $query, ?int $storeId = null, int $limit = 20): array
    {
        $orders = $this->order->searchOrders($query, $storeId, $limit);
        $completeOrders = [];

        foreach ($orders as $order) {
            $completeOrder = $this->getCompleteOrder($order['id']);
            if ($completeOrder) {
                $completeOrders[] = $completeOrder;
            }
        }

        return $completeOrders;
    }

    /**
     * Get order summary for reporting
     */
    public function getOrderSummary(int $storeId, string $startDate, string $endDate): array
    {
        $sql = "SELECT 
            DATE(o.created_at) as date,
            COUNT(*) as order_count,
            SUM(o.total_amount) as total_revenue,
            AVG(o.total_amount) as average_order_value,
            COUNT(CASE WHEN o.status = 'completed' THEN 1 END) as completed_orders,
            COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) as cancelled_orders,
            SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) / COUNT(*) * 100 as completion_rate
        FROM commerce_orders o
        WHERE o.store_id = ? AND o.created_at BETWEEN ? AND ?
        GROUP BY DATE(o.created_at)
        ORDER BY date DESC";

        return $this->db->fetchAll($sql, $storeId, $startDate, $endDate);
    }

    /**
     * Export orders to CSV
     */
    public function exportOrdersToCSV($orders): string
    {
        $csv = "ID,Order Number,Customer ID,Customer Email,Store ID,Status,Payment Status,Currency,Subtotal,Tax Amount,Shipping Amount,Discount Amount,Total Amount,Refund Amount,Adjustments,Customer IP,Customer User Agent,Notes,Customer Notes,Admin Notes,Created At,Updated At,Completed At,Cancelled At,Refunded At\n";

        foreach ($orders as $order) {
            $customer = $this->customer->getCustomer($order['customer_id']);

            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $order['id'],
                $order['order_number'],
                $order['customer_id'],
                $customer['email'] ?? '',
                $order['store_id'],
                $order['status'],
                $order['payment_status'],
                $order['currency'],
                (float)$order['subtotal'],
                (float)$order['tax_amount'],
                (float)$order['shipping_amount'],
                (float)$order['discount_amount'],
                (float)$order['total_amount'],
                (float)$order['refund_amount'],
                $order['adjustments'] ?? '',
                $order['customer_ip'] ?? '',
                $order['customer_user_agent'] ?? '',
                '"' . str_replace('"', '""', $order['notes'] ?? '') . '"',
                '"' . str_replace('"', '""', $order['customer_notes'] ?? '') . '"',
                '"' . str_replace('"', '""', $order['admin_notes'] ?? '') . '"',
                $order['created_at'],
                $order['updated_at'],
                $order['completed_at'] ?? '',
                $order['cancelled_at'] ?? '',
                $order['refunded_at'] ?? ''
            );
        }

        return $csv;

    }

    /**
     * Cleanup old data
     */
    public function cleanupOldData(int $daysOld = 365): array
    {
        return [
            'activities_deleted' => $this->orderActivity->deleteOldActivities($daysOld)
        ];
    }

    public function shippingManager(): ShipmentMethodManager
    {
        return $this->shipmentMethodManager;
    }
}
