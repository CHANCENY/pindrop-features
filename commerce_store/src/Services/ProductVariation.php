<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Services;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class ProductVariation
{
    protected DatabaseService $db;
    protected LoggerInterface $logger;
    protected ?int $productId = null;

    public function __construct(DatabaseService $database, LoggerInterface $logger)
    {
        $this->db = $database;
        $this->logger = $logger;
    }

    /**
     * Get variation by ID
     */
    public function getVariation(int $variationId): ?array
    {
        $sql = "SELECT * FROM commerce_product_variations WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetch($sql, $variationId);
    }

    /**
     * Get variation by UUID
     */
    public function getVariationByUuid(string $uuid): ?array
    {
        $sql = "SELECT * FROM commerce_product_variations WHERE uuid = ? AND deleted_at IS NULL";
        return $this->db->fetch($sql, $uuid);
    }

    /**
     * Get variation by SKU
     */
    public function getVariationBySku(string $sku, ?int $productId = null): ?array
    {
        $sql = "SELECT * FROM commerce_product_variations WHERE sku = ? AND product_id = ? AND deleted_at IS NULL";
        $params = [$sku, $productId ?? $this->productId];
        
        if (!$productId && !$this->productId) {
            $sql = "SELECT * FROM commerce_product_variations WHERE sku = ? AND deleted_at IS NULL";
            $params = [$sku];
        }
        
        return $this->db->fetch($sql, ...$params);
    }

    /**
     * Get variations by product ID
     */
    public function getVariationsByProduct(int $productId): array
    {
        $sql = "SELECT * FROM commerce_product_variations 
                 WHERE product_id = ? AND deleted_at IS NULL 
                 ORDER BY menu_order ASC, name ASC";
        return $this->db->fetchAll($sql, $productId);
    }

    /**
     * Create new variation
     */
    public function createVariation(array $data): int
    {
        // Validate required fields
        $this->validateVariationData($data);

        // Set defaults
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['status'] = $data['status'] ?? 'draft';
        $data['catalog_visibility'] = $data['catalog_visibility'] ?? 'visible';
        $data['manage_stock'] = $data['manage_stock'] ?? true;
        $data['stock_status'] = $data['stock_status'] ?? 'instock';
        $data['virtual'] = $data['virtual'] ?? false;
        $data['downloadable'] = $data['downloadable'] ?? false;
        $data['shipping_required'] = $data['shipping_required'] ?? true;
        $data['tax_status'] = $data['tax_status'] ?? 'taxable';
        $data['uuid'] = $this->generateUuid();

        // Handle JSON fields
        $jsonFields = ['attributes', 'meta_data'];
        foreach ($jsonFields as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }

        $sql = "INSERT INTO commerce_product_variations (
            product_id, sku, name, slug, description, status, featured, catalog_visibility,
            regular_price, sale_price, sale_price_start_date, sale_price_end_date, tax_status,
            tax_class, manage_stock, stock_quantity, stock_status, backorders_allowed,
            sold_individually, weight, dimensions_length, dimensions_width, dimensions_height,
            shipping_class, shipping_required, purchase_note, menu_order, virtual, downloadable,
            download_limit, download_expiry, image_id, attributes, meta_data, created_at,
            updated_at, created_by, updated_by, published_at
        ) VALUES (
            :product_id, :sku, :name, :slug, :description, :status, :featured, :catalog_visibility,
            :regular_price, :sale_price, :sale_price_start_date, :sale_price_end_date, :tax_status,
            :tax_class, :manage_stock, :stock_quantity, :stock_status, :backorders_allowed,
            :sold_individually, :weight, :dimensions_length, :dimensions_width, :dimensions_height,
            :shipping_class, :shipping_required, :purchase_note, :menu_order, :virtual, :downloadable,
            :download_limit, :download_expiry, :image_id, :attributes, :meta_data, :created_at,
            :updated_at, :created_by, :updated_by, :published_at
        )";

        return $this->db->insert('commerce_product_variations', $data);
    }

    /**
     * Update variation
     */
    public function updateVariation(int $variationId, array $data): bool
    {
        // Validate required fields
        $this->validateVariationData($data);

        if (isset($data['op'])){
            unset($data['op']);
        }

        // Set updated timestamp
        $data['updated_at'] = date('Y-m-d H:i:s');

        // Handle JSON fields
        $jsonFields = ['attributes', 'meta_data'];
        foreach ($jsonFields as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }

        $data['id'] = $variationId;

        return $this->db->update('commerce_product_variations', $data, 'id = ?', $variationId);
    }

    /**
     * Delete variation (soft delete)
     */
    public function deleteVariation(int $variationId): bool
    {
        return $this->db->update('commerce_product_variations', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', $variationId);
    }

    /**
     * Restore variation
     */
    public function restoreVariation(int $variationId): bool
    {
        return $this->db->update('commerce_product_variations', [
            'deleted_at' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', $variationId);
    }

    /**
     * Get variation count by product
     */
    public function getVariationCount(int $productId): int
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_product_variations WHERE product_id = ? AND deleted_at IS NULL";
        return (int) $this->db->fetch($sql, $productId)['count'] ?? 0;
    }

    /**
     * Get featured variations
     */
    public function getFeaturedVariations(int $productId, int $limit = 10): array
    {
        $sql = "SELECT * FROM commerce_product_variations 
                 WHERE product_id = ? AND featured = 1 AND status = 'publish' AND deleted_at IS NULL 
                 ORDER BY menu_order ASC, name ASC 
                 LIMIT ?";
        return $this->db->fetchAll($sql, $productId, $limit);
    }

    /**
     * Update variation stock
     */
    public function updateStock(int $variationId, int $quantity, ?string $status = null): bool
    {
        $updateData = [
            'stock_quantity' => $quantity,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($status) {
            $updateData['stock_status'] = $status;
        }

        return $this->db->update('commerce_product_variations', $updateData, 'id = ?', $variationId);
    }

    /**
     * Get variations by status
     */
    public function getVariationsByStatus(int $productId, string $status): array
    {
        $sql = "SELECT * FROM commerce_product_variations 
                 WHERE product_id = ? AND status = ? AND deleted_at IS NULL 
                 ORDER BY menu_order ASC, name ASC";
        return $this->db->fetchAll($sql, $productId, $status);
    }

    /**
     * Get in-stock variations
     */
    public function getInStockVariations(int $productId): array
    {
        $sql = "SELECT * FROM commerce_product_variations 
                 WHERE product_id = ? AND stock_status = 'instock' AND deleted_at IS NULL 
                 ORDER BY menu_order ASC, name ASC";
        return $this->db->fetchAll($sql, $productId);
    }

    /**
     * Update variation menu order
     */
    public function updateMenuOrder(int $variationId, int $order): bool
    {
        return $this->db->update('commerce_product_variations', [
            'menu_order' => $order,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', $variationId);
    }

    /**
     * Bulk update menu orders
     */
    public function updateMenuOrders(array $variationOrders): bool
    {
        $sql = "UPDATE commerce_product_variations SET menu_order = CASE id ";
        foreach ($variationOrders as $variationId => $order) {
            $sql .= "WHEN {$variationId} THEN {$order} ";
        }
        $sql .= "END, updated_at = ? WHERE id IN (";
        $sql .= implode(',', array_keys($variationOrders)) . ")";

        return $this->db->query($sql, date('Y-m-d H:i:s'));
    }

    /**
     * Get variation with attributes
     */
    public function getVariationWithAttributes(int $variationId): ?array
    {
        $variation = $this->getVariation($variationId);
        if (!$variation) {
            return null;
        }

        // Get attributes
        $sql = "SELECT * FROM commerce_variation_attributes WHERE variation_id = ? ORDER BY attribute_order ASC";
        $attributes = $this->db->fetchAll($sql, $variationId);

        $variation['attributes'] = $attributes;
        return $variation;
    }

    /**
     * Check if variation exists
     */
    public function variationExists(int $variationId): bool
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_product_variations WHERE id = ? AND deleted_at IS NULL";
        return (int) $this->db->fetch($sql, $variationId)['count'] ?? 0 > 0;
    }

    /**
     * Validate variation data
     */
    protected function validateVariationData(array $data): void
    {
        $required = ['name', 'product_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Validate SKU uniqueness within product
        if (isset($data['sku']) && isset($data['product_id'])) {
            $existing = $this->getVariationBySku($data['sku'], $data['product_id']);

            if (!isset($data['op'])){
                if ($existing && (!isset($data['id']) || $existing['id'] != $data['id'])) {
                    throw new \InvalidArgumentException("SKU must be unique within product");
                }
            }
            elseif ($data['op'] !== 'update') {
                throw new \InvalidArgumentException("SKU must be unique within product");
            }

        }

        // Validate price
        if (isset($data['regular_price']) && $data['regular_price'] <= 0) {
            throw new \InvalidArgumentException("Regular price must be greater than 0");
        }

        if (isset($data['sale_price']) && $data['sale_price'] <= 0) {
            throw new \InvalidArgumentException("Sale price must be greater than 0");
        }

        // Validate stock
        if (isset($data['stock_quantity']) && $data['stock_quantity'] < 0) {
            throw new \InvalidArgumentException("Stock quantity cannot be negative");
        }
    }

    /**
     * Get product ID
     */
    public function getProductId(): ?int
    {
        return $this->productId;
    }

    /**
     * Set product ID
     */
    public function setProductId(int $productId): void
    {
        $this->productId = $productId;
    }

    /**
     * Duplicate variation
     */
    public function duplicateVariation(int $variationId, array $overrides = []): int
    {
        $variation = $this->getVariation($variationId);
        if (!$variation) {
            throw new \InvalidArgumentException('Variation not found');
        }

        // Create duplicate with overrides
        $duplicateData = $variation;
        unset($duplicateData['id'], $duplicateData['uuid'], $duplicateData['created_at'], $duplicateData['updated_at']);
        
        // Apply overrides
        foreach ($overrides as $field => $value) {
            $duplicateData[$field] = $value;
        }

        // Generate new SKU if not provided
        if (empty($duplicateData['sku'])) {
            $duplicateData['sku'] = $variation['sku'] . '-copy-' . time();
        }

        // Generate new slug if not provided
        if (empty($duplicateData['slug'])) {
            $duplicateData['slug'] = $variation['slug'] . '-copy-' . time();
        }

        return $this->createVariation($duplicateData);
    }

    /**
     * Get variations with images
     */
    public function getVariationsWithImages(int $productId): array
    {
        $variations = $this->getVariationsByProduct($productId);
        
        foreach ($variations as &$variation) {
            if ($variation['image_id']) {
                $sql = "SELECT * FROM commerce_variation_images WHERE variation_id = ? ORDER BY image_order ASC";
                $variation['images'] = $this->db->fetchAll($sql, $variation['id']);
            } else {
                $variation['images'] = [];
            }
        }

        return $variations;
    }

    /**
     * Get variation attributes
     * @throws DatabaseException
     */
    public function getVariationAttributes(int $variationId): array
    {
        $sql = "SELECT * FROM commerce_variation_attributes WHERE variation_id = ? ORDER BY attribute_order ASC";
        return $this->db->fetchAll($sql, $variationId) ?: [];
    }

    /**
     * Get variation images
     * @throws DatabaseException
     */
    public function getVariationImages(int $variationId): array
    {
        $sql = "SELECT * FROM commerce_variation_images WHERE variation_id = ? ORDER BY image_order ASC";
        return $this->db->fetchAll($sql, $variationId) ?: [];
    }

    protected function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}