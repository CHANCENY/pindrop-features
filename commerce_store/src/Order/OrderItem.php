<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Order;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class OrderItem
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
     * Add item to order
     * @throws DatabaseException
     */
    public function addOrderItem(int $orderId, array $itemData): int
    {
        $this->validateOrderItemData($itemData);

        // Set defaults
        $itemData['order_id'] = $orderId;
        $itemData['created_at'] = date('Y-m-d H:i:s');
        $itemData['updated_at'] = date('Y-m-d H:i:s');
        $itemData['quantity'] = $itemData['quantity'] ?? 1;
        $itemData['unit_price'] = $itemData['unit_price'] ?? 0;
        $itemData['total_price'] = $itemData['quantity'] * $itemData['unit_price'];
        $itemData['tax_amount'] = $itemData['tax_amount'] ?? 0;
        $itemData['discount_amount'] = $itemData['discount_amount'] ?? 0;
        $itemData['status'] = $itemData['status'] ?? 'pending';

        // Handle JSON fields
        if (isset($itemData['item_attributes']) && is_array($itemData['item_attributes'])) {
            $itemData['item_attributes'] = json_encode($itemData['item_attributes']);
        }
        else {
            $itemData['item_attributes'] = null;
        }

        $sql = "INSERT INTO commerce_order_item (
    order_id, product_id, variation_id, quantity, unit_price, total_price,
    tax_amount, discount_amount, item_name, item_sku, item_attributes,
    weight, dimensions_length, dimensions_width, dimensions_height,
    shipping_class, `virtual`, downloadable, `status`, notes,
    created_at, updated_at
) VALUES (
    :order_id, :product_id, :variation_id, :quantity, :unit_price, :total_price,
    :tax_amount, :discount_amount, :item_name, :item_sku, :item_attributes,
    :weight, :dimensions_length, :dimensions_width, :dimensions_height,
    :shipping_class, :virtual, :downloadable, :status, :notes,
    :created_at, :updated_at
);
";
        $this->db->query($sql, ...$itemData);
        $itemId = $this->db->lastInsertId();
        
        $this->logger->info('Order item added', ['order_id' => $orderId, 'item_id' => $itemId, 'product_id' => $itemData['product_id']]);
        
        return $itemId;
    }

    /**
     * Get order items
     */
    public function getOrderItems(int $orderId): array
    {
        $sql = "SELECT * FROM commerce_order_item WHERE order_id = ? ORDER BY created_at ASC";
        $items = $this->db->fetchAll($sql, $orderId);
        
        // Decode JSON fields
        foreach ($items as &$item) {
            if ($item['item_attributes']) {
                $item['item_attributes'] = json_decode($item['item_attributes'], true);
            }
        }
        
        return $items;
    }

    /**
     * Get order item by ID
     */
    public function getOrderItem(int $itemId): ?array
    {
        $sql = "SELECT * FROM commerce_order_item WHERE id = ?";
        $item = $this->db->fetch($sql, $itemId);
        
        if ($item && $item['item_attributes']) {
            $item['item_attributes'] = json_decode($item['item_attributes'], true);
        }
        
        return $item;
    }

    /**
     * Update order item status
     */
    public function updateOrderItemStatus(int $itemId, string $status): bool
    {
        $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $sql = "UPDATE commerce_order_item SET status = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, $status, date('Y-m-d H:i:s'), $itemId);
        
        $this->logger->info('Order item status updated', ['item_id' => $itemId, 'status' => $status]);
        
        return true;
    }

    /**
     * @throws DatabaseException
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function updateOrderItemQuantityCount(int $itemId, int $count): false|int
    {
        $itemData = $this->getOrderItem($itemId);
        $container = \getAppContainer();
        if (!empty($itemData['variation_id'])) {

            $productVariation = $container->get('commerce_store.product_variations')->getVariation($itemData['variation_id']);

            if (!empty($productVariation['stock_status']) && $productVariation['stock_status'] === 'instock') {
                if (!empty($productVariation['stock_quantity']) && $productVariation['stock_quantity'] >= $count) {
                    $sql = "UPDATE commerce_order_item SET quantity = ?, updated_at = ? WHERE id = ?";
                    $r = $this->db->query($sql, $count, date('Y-m-d H:i:s'), $itemId);
                    $this->logger->info('Order item quantity updated', ['item_id' => $itemId, 'count' => $count]);
                    return $r instanceof \PDOStatement ? $r->rowCount() : 0;
                }
            }

        }
        return false;
    }

    /**
     * Delete order item
     */
    public function deleteOrderItem(int $itemId): bool
    {
        $sql = "DELETE FROM commerce_order_item WHERE id = ?";
        $this->db->query($sql, $itemId);
        
        $this->logger->info('Order item deleted', ['item_id' => $itemId]);
        
        return true;
    }

    /**
     * @throws DatabaseException
     */
    public function deleteItems(int $orderId): int
    {
        $sql = "DELETE FROM commerce_order_item WHERE order_id = ?";
        return $this->db->query($sql, $orderId)->rowCount();
    }

    /**
     * Get items by product ID
     */
    public function getItemsByProduct(int $productId, int $limit = 50): array
    {
        $sql = "SELECT * FROM commerce_order_item WHERE product_id = ? ORDER BY created_at DESC LIMIT ?";
        $items = $this->db->fetchAll($sql, $productId, $limit);
        
        // Decode JSON fields
        foreach ($items as &$item) {
            if ($item['item_attributes']) {
                $item['item_attributes'] = json_decode($item['item_attributes'], true);
            }
        }
        
        return $items;
    }

    /**
     * Get items by variation ID
     */
    public function getItemsByVariation(int $variationId, int $limit = 50): array
    {
        $sql = "SELECT * FROM commerce_order_item WHERE variation_id = ? ORDER BY created_at DESC LIMIT ?";
        $items = $this->db->fetchAll($sql, $variationId, $limit);
        
        // Decode JSON fields
        foreach ($items as &$item) {
            if ($item['item_attributes']) {
                $item['item_attributes'] = json_decode($item['item_attributes'], true);
            }
        }
        
        return $items;
    }

    /**
     * Get item statistics
     */
    public function getItemStats(int $storeId, ?string $startDate = null, ?string $endDate = null): array
    {
        $whereClause = "WHERE oi.order_id IN (SELECT id FROM commerce_orders WHERE store_id = ?)";
        $params = [$storeId];

        if ($startDate) {
            $whereClause .= " AND oi.created_at >= ?";
            $params[] = $startDate;
        }

        if ($endDate) {
            $whereClause .= " AND oi.created_at <= ?";
            $params[] = $endDate;
        }

        $sql = "SELECT 
            COUNT(*) as total_items,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.total_price) as total_revenue,
            AVG(oi.unit_price) as average_price,
            COUNT(DISTINCT oi.product_id) as unique_products,
            COUNT(CASE WHEN oi.virtual = 1 THEN 1 END) as virtual_items,
            COUNT(CASE WHEN oi.downloadable = 1 THEN 1 END) as downloadable_items
        FROM commerce_order_item oi {$whereClause}";

        return $this->db->fetch($sql, ...$params);
    }

    /**
     * Get top selling products
     */
    public function getTopSellingProducts(int $storeId, int $limit = 10): array
    {
        $sql = "SELECT 
            oi.product_id,
            oi.item_name,
            SUM(oi.quantity) as total_sold,
            SUM(oi.total_price) as total_revenue,
            AVG(oi.unit_price) as average_price,
            COUNT(*) as order_count
        FROM commerce_order_item oi
        JOIN commerce_orders o ON oi.order_id = o.id
        WHERE o.store_id = ?
        GROUP BY oi.product_id, oi.item_name
        ORDER BY total_sold DESC
        LIMIT ?";
        
        return $this->db->fetchAll($sql, $storeId, $limit);
    }

    /**
     * Get item revenue by date range
     */
    public function getItemRevenueByDate(int $storeId, string $startDate, string $endDate): array
    {
        $sql = "SELECT 
            DATE(oi.created_at) as date,
            SUM(oi.quantity) as daily_quantity,
            SUM(oi.total_price) as daily_revenue,
            COUNT(*) as daily_orders
        FROM commerce_order_item oi
        JOIN commerce_orders o ON oi.order_id = o.id
        WHERE o.store_id = ? AND oi.created_at BETWEEN ? AND ?
        GROUP BY DATE(oi.created_at)
        ORDER BY date DESC";
        
        return $this->db->fetchAll($sql, $storeId, $startDate, $endDate);
    }

    /**
     * Refund order item
     */
    public function refundOrderItem(int $itemId, float $refundAmount, string $reason = ''): bool
    {
        $item = $this->getOrderItem($itemId);
        if (!$item) {
            throw new \InvalidArgumentException('Order item not found');
        }

        if ($refundAmount > $item['total_price']) {
            throw new \InvalidArgumentException('Refund amount cannot exceed item total price');
        }

        $this->updateOrderItemStatus($itemId, 'refunded');
        
        $this->logger->info('Order item refunded', ['item_id' => $itemId, 'amount' => $refundAmount, 'reason' => $reason]);
        
        return true;
    }

    /**
     * Get low stock items (based on order history)
     */
    public function getLowStockItems(int $storeId, int $threshold = 10): array
    {
        $sql = "SELECT 
            oi.product_id,
            oi.item_name,
            SUM(oi.quantity) as total_ordered,
            COUNT(*) as order_frequency
        FROM commerce_order_item oi
        JOIN commerce_orders o ON oi.order_id = o.id
        WHERE o.store_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY oi.product_id, oi.item_name
        HAVING total_ordered >= ?
        ORDER BY total_ordered DESC";
        
        return $this->db->fetchAll($sql, $storeId, $threshold);
    }

    /**
     * Validate order item data
     */
    protected function validateOrderItemData(array $data, bool $isUpdate = false): void
    {
        $required = ['product_id', 'item_name'];
        if (!$isUpdate) {
            $required[] = 'order_id';
        }

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (isset($data['quantity']) && $data['quantity'] <= 0) {
            throw new \InvalidArgumentException("Quantity must be positive");
        }

        if (isset($data['unit_price']) && $data['unit_price'] < 0) {
            throw new \InvalidArgumentException("Unit price cannot be negative");
        }

        if (isset($data['total_price']) && $data['total_price'] < 0) {
            throw new \InvalidArgumentException("Total price cannot be negative");
        }

        if (isset($data['status'])) {
            $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
            if (!in_array($data['status'], $validStatuses)) {
                throw new \InvalidArgumentException("Invalid status: {$data['status']}");
            }
        }
    }
}
