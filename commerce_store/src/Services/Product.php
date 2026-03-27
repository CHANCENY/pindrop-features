<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Services;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;
use Simp\Pindrop\Modules\commerce_store\src\Plugin\Price;
use Simp\Pindrop\Modules\commerce_store\src\Plugin\Adjustment;

class Product
{
    protected DatabaseService $db;
    protected LoggerInterface $logger;
    protected ?int $storeId = null;

    public function __construct(DatabaseService $database, LoggerInterface $logger)
    {
        $this->db = $database;
        $this->logger = $logger;
    }

    /**
     * Get product by ID
     */
    public function getProduct(int $productId): ?array
    {
        $sql = "SELECT * FROM commerce_products WHERE id = ? AND deleted_at IS NULL";
        return $this->db->fetch($sql, $productId);
    }

    /**
     * Get product by UUID
     */
    public function getProductByUuid(string $uuid): ?array
    {
        $sql = "SELECT * FROM commerce_products WHERE uuid = ? AND deleted_at IS NULL";
        return $this->db->fetch($sql, $uuid);
    }

    /**
     * Get product by SKU
     */
    public function getProductBySku(string $sku, ?int $storeId = null): ?array
    {
        $sql = "SELECT * FROM commerce_products WHERE sku = ? AND store_id = ? AND deleted_at IS NULL";
        $params = [$sku, $storeId ?? $this->storeId];
        
        if (!$storeId && !$this->storeId) {
            $sql = "SELECT * FROM commerce_products WHERE sku = ? AND deleted_at IS NULL";
            $params = [$sku];
        }
        
        return $this->db->fetch($sql, ...$params);
    }

    /**
     * Get product by slug
     */
    public function getProductBySlug(string $slug, ?int $storeId = null): ?array
    {
        $sql = "SELECT * FROM commerce_products WHERE slug = ? AND store_id = ? AND deleted_at IS NULL";
        $params = [$slug, $storeId ?? $this->storeId];
        
        if (!$storeId && !$this->storeId) {
            $sql = "SELECT * FROM commerce_products WHERE slug = ? AND deleted_at IS NULL";
            $params = [$slug];
        }
        
        return $this->db->fetchRow($sql, $params);
    }

    /**
     * Get products by store ID
     */
    public function getProductsByStore(int $storeId, array $filters = []): array
    {
        $sql = "SELECT * FROM commerce_products WHERE store_id = ? AND deleted_at IS NULL";
        $params = [$storeId];

        // Apply filters
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['featured'])) {
            $sql .= " AND featured = ?";
            $params[] = $filters['featured'];
        }

        if (!empty($filters['catalog_visibility'])) {
            $sql .= " AND catalog_visibility = ?";
            $params[] = $filters['catalog_visibility'];
        }

        if (!empty($filters['stock_status'])) {
            $sql .= " AND stock_status = ?";
            $params[] = $filters['stock_status'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND JSON_CONTAINS(categories, ?)";
            $params[] = json_encode($filters['category']);
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (MATCH(name, description, short_description) AGAINST(? IN NATURAL LANGUAGE MODE))";
            $params[] = $filters['search'];
        }

        // Order by
        $sql .= " ORDER BY ";
        if (!empty($filters['order_by'])) {
            $sql .= $filters['order_by'];
        } else {
            $sql .= "menu_order ASC, name ASC";
        }

        // Limit
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int) $filters['limit'];
        }

        if (!empty($filters['offset'])) {
            $sql .= " OFFSET ?";
            $params[] = (int) $filters['offset'];
        }

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Get total count of products for a store with filters
     */
    public function getProductsCountByStore(int $storeId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total FROM commerce_products WHERE store_id = ? AND deleted_at IS NULL";
        $params = [$storeId];

        // Apply filters (same as getProductsByStore but without LIMIT/OFFSET)
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['featured'])) {
            $sql .= " AND featured = ?";
            $params[] = $filters['featured'];
        }

        if (!empty($filters['catalog_visibility'])) {
            $sql .= " AND catalog_visibility = ?";
            $params[] = $filters['catalog_visibility'];
        }

        if (!empty($filters['stock_status'])) {
            $sql .= " AND stock_status = ?";
            $params[] = $filters['stock_status'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND JSON_CONTAINS(categories, ?)";
            $params[] = json_encode($filters['category']);
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (MATCH(name, description, short_description) AGAINST(? IN NATURAL LANGUAGE MODE))";
            $params[] = $filters['search'];
        }

        $result = $this->db->fetchAll($sql, ...$params);
        
        return $result[0]['total'] ?? 0;
    }

    /**
     * Create new product variation
     */
    public function createVariation(array $data): int
    {
        // Validate required fields
        if (empty($data['product_id']) || empty($data['sku']) || empty($data['name'])) {
            throw new \InvalidArgumentException("Product ID, SKU, and Name are required");
        }

        if (!is_numeric($data['regular_price']) || $data['regular_price'] <= 0) {
            throw new \InvalidArgumentException("Regular price must be greater than 0");
        }

        // Set defaults
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['status'] = $data['status'] ?? 'publish';
        $data['manage_stock'] = $data['manage_stock'] ?? 1;
        $data['stock_status'] = $data['stock_quantity'] > 0 ? 'instock' : 'outofstock';

        // Handle JSON fields
        if (isset($data['attributes']) && is_array($data['attributes'])) {
            $data['attributes'] = json_encode($data['attributes']);
        }

        return $this->db->insert('commerce_product_variations', $data);
    }

    /**
     * Update product variation
     */
    public function updateVariation(int $variationId, array $data): bool
    {
        // Validate required fields
        if (empty($data['sku']) || empty($data['name'])) {
            throw new \InvalidArgumentException("SKU and Name are required");
        }

        if (!is_numeric($data['regular_price']) || $data['regular_price'] <= 0) {
            throw new \InvalidArgumentException("Regular price must be greater than 0");
        }

        // Set update timestamp
        $data['updated_at'] = date('Y-m-d H:i:s');

        // Handle JSON fields
        if (isset($data['attributes']) && is_array($data['attributes'])) {
            $data['attributes'] = json_encode($data['attributes']);
        }

        return $this->db->update('commerce_product_variations', $data, 'id = ?', $variationId);
    }

    /**
     * Delete product variation (soft delete)
     */
    public function deleteVariation(int $variationId): bool
    {
        return $this->db->update('commerce_product_variations', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', $variationId);
    }

    /**
     * Create new product
     */
    public function createProduct(array $data): int
    {
        // Validate required fields
        $this->validateProductData($data);

        // Set defaults
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['status'] = $data['status'] ?? 'draft';
        $data['type'] = $data['type'] ?? 'simple';
        $data['catalog_visibility'] = $data['catalog_visibility'] ?? 'visible';
        $data['manage_stock'] = $data['manage_stock'] ?? true;
        $data['stock_status'] = $data['stock_status'] ?? 'instock';
        $data['virtual'] = $data['virtual'] ?? false;
        $data['downloadable'] = $data['downloadable'] ?? false;
        $data['reviews_allowed'] = $data['reviews_allowed'] ?? true;
        $data['shipping_required'] = $data['shipping_required'] ?? true;
        $data['tax_status'] = $data['tax_status'] ?? 'taxable';
        $data['uuid'] = $this->generateUuid();

        // Handle JSON fields
        $jsonFields = ['categories', 'tags', 'attributes', 'default_attributes', 'variations', 'meta_data', 'grouped_products', 'upsell_products', 'cross_sell_products'];
        foreach ($jsonFields as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }

        $sql = "INSERT INTO commerce_products (
            store_id, sku, name, slug, description, short_description, type, status, featured,
            catalog_visibility, regular_price, sale_price, sale_price_start_date, sale_price_end_date,
            tax_status, tax_class, manage_stock, stock_quantity, stock_status, backorders_allowed,
            sold_individually, weight, dimensions_length, dimensions_width, dimensions_height,
            shipping_class, shipping_required, purchase_note, menu_order, reviews_allowed,
            average_rating, rating_count, total_sales, virtual, downloadable, download_limit,
            download_expiry, external_url, button_text, parent_id, grouped_products,
            upsell_products, cross_sell_products, categories, tags, attributes, default_attributes,
            variations, meta_data, created_at, updated_at, created_by, updated_by, published_at
        ) VALUES (
            :store_id, :sku, :name, :slug, :description, :short_description, :type, :status, :featured,
            :catalog_visibility, :regular_price, :sale_price, :sale_price_start_date, :sale_price_end_date,
            :tax_status, :tax_class, :manage_stock, :stock_quantity, :stock_status, :backorders_allowed,
            :sold_individually, :weight, :dimensions_length, :dimensions_width, :dimensions_height,
            :shipping_class, :shipping_required, :purchase_note, :menu_order, :reviews_allowed,
            :average_rating, :rating_count, :total_sales, :virtual, :downloadable, :download_limit,
            :download_expiry, :external_url, :button_text, :parent_id, :grouped_products,
            :upsell_products, :cross_sell_products, :categories, :tags, :attributes, :default_attributes,
            :variations, :meta_data, :created_at, :updated_at, :created_by, :updated_by, :published_at
        )";

        return $this->db->insert('commerce_products', $data);
    }

    /**
     * Update product
     */
    public function updateProduct(int $productId, array $data): bool
    {
        // Validate required fields
        $this->validateProductData($data);
        if (!empty($data['op'])) {
            unset($data['op']);
        }

        // Set updated timestamp
        $data['updated_at'] = date('Y-m-d H:i:s');

        // Handle JSON fields
        $jsonFields = ['categories', 'tags', 'attributes', 'default_attributes', 'variations', 'meta_data', 'grouped_products', 'upsell_products', 'cross_sell_products'];
        foreach ($jsonFields as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }

        $data['id'] = $productId;

        return $this->db->update('commerce_products', $data, 'id = ?', $productId);
    }

    /**
     * Delete product (soft delete)
     */
    public function deleteProduct(int $productId): bool
    {
        return $this->db->update('commerce_products', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', $productId);
    }

    /**
     * Restore product
     */
    public function restoreProduct(int $productId): bool
    {
        return $this->db->update('commerce_products', [
            'deleted_at' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', $productId);
    }

    /**
     * Get product count by store
     */
    public function getProductCount(int $storeId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_products WHERE store_id = ? AND deleted_at IS NULL";
        $params = [$storeId];

        // Apply filters
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['featured'])) {
            $sql .= " AND featured = ?";
            $params[] = $filters['featured'];
        }

        if (!empty($filters['stock_status'])) {
            $sql .= " AND stock_status = ?";
            $params[] = $filters['stock_status'];
        }

        return (int) $this->db->fetch($sql, ...$params)['count'] ?? 0;
    }

    /**
     * Get featured products
     */
    public function getFeaturedProducts(int $storeId, int $limit = 10): array
    {
        $sql = "SELECT * FROM commerce_products 
                 WHERE store_id = ? AND featured = 1 AND status = 'publish' AND deleted_at IS NULL 
                 ORDER BY menu_order ASC, name ASC 
                 LIMIT ?";
        return $this->db->fetchAll($sql, $storeId, $limit);
    }

    /**
     * Get products by category
     */
    public function getProductsByCategory(int $storeId, string $category, int $limit = 20): array
    {
        $sql = "SELECT * FROM commerce_products 
                 WHERE store_id = ? AND JSON_CONTAINS(categories, ?) AND status = 'publish' AND deleted_at IS NULL 
                 ORDER BY menu_order ASC, name ASC 
                 LIMIT ?";
        return $this->db->fetchAll($sql, $storeId, json_encode($category), $limit);
    }

    /**
     * Search products
     */
    public function searchProducts(int $storeId, string $query, int $limit = 20): array
    {
        $sql = "SELECT * FROM commerce_products 
                 WHERE store_id = ? AND (MATCH(name, description, short_description) AGAINST(? IN NATURAL LANGUAGE MODE)) 
                 AND status = 'publish' AND catalog_visibility IN ('visible', 'catalog') AND deleted_at IS NULL 
                 ORDER BY total_sales DESC, name ASC 
                 LIMIT ?";
        return $this->db->fetchAll($sql, $storeId, $query, $limit);
    }

    /**
     * Get related products (upsell and cross-sell)
     */
    public function getRelatedProducts(int $productId, int $limit = 10): array
    {
        $product = $this->getProduct($productId);
        if (!$product) {
            return [];
        }

        $relatedIds = [];
        if (!empty($product['upsell_products'])) {
            $relatedIds = array_merge($relatedIds, json_decode($product['upsell_products'], true));
        }
        if (!empty($product['cross_sell_products'])) {
            $relatedIds = array_merge($relatedIds, json_decode($product['cross_sell_products'], true));
        }

        if (empty($relatedIds)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($relatedIds));
        $placeholders = rtrim($placeholders, ',');
        $sql = "SELECT * FROM commerce_products 
                 WHERE id IN ($placeholders) AND status = 'publish' AND deleted_at IS NULL 
                 ORDER BY total_sales DESC, name ASC 
                 LIMIT ?";
        $params = array_merge($relatedIds, [$limit]);

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Update stock
     */
    public function updateStock(int $productId, int $quantity, ?string $status = null): bool
    {
        return $this->db->update('commerce_products', [
            'stock_quantity' => $quantity,
            'updated_at' => date('Y-m-d H:i:s'),
            'stock_status' => $status
        ], 'id = ?', $productId);
    }

    /**
     * Validate product data
     */
    protected function validateProductData(array $data): void
    {
        $required = ['name', 'sku', 'store_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Validate SKU uniqueness within store
        if (isset($data['sku']) && isset($data['store_id'])) {
            $existing = $this->getProductBySku($data['sku'], $data['store_id']);
            if ($existing && (!isset($data['id']) || $existing['id'] != $data['id'])) {
                if (!isset($data['op'])) {
                    throw new \InvalidArgumentException("SKU must be unique within store");
                }
                elseif ($data['op'] != 'update') {
                    throw new \InvalidArgumentException("SKU must be unique within store");
                }
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
     * Get product variations
     * @throws DatabaseException
     */
    public function getProductVariations(int $productId, array $options = []): array
    {
        $limit = $options['limit'] ?? null;
        $offset = $options['offset'] ?? 0;
        
        $sql = "SELECT * FROM commerce_product_variations 
                 WHERE product_id = ? AND deleted_at IS NULL";
        $params = [$productId];
        
        // Add filters
        if (!empty($options['status'])) {
            $sql .= " AND status = ?";
            $params[] = $options['status'];
        }
        
        if (!empty($options['stock_status'])) {
            $sql .= " AND stock_status = ?";
            $params[] = $options['stock_status'];
        }
        
        if (isset($options['featured']) && $options['featured'] !== null && $options['featured'] !== '') {
            $sql .= " AND featured = ?";
            $params[] = $options['featured'];
        }
        
        if (!empty($options['catalog_visibility'])) {
            $sql .= " AND catalog_visibility = ?";
            $params[] = $options['catalog_visibility'];
        }
        
        if (isset($options['manage_stock']) && $options['manage_stock'] !== null && $options['manage_stock'] !== '') {
            $sql .= " AND manage_stock = ?";
            $params[] = $options['manage_stock'];
        }
        
        if (!empty($options['search'])) {
            $sql .= " AND (name LIKE ? OR sku LIKE ?)";
            $searchTerm = '%' . $options['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY menu_order ASC, name ASC";
        
        if ($limit) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }
        
        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Get product variations count
     */
    public function getProductVariationsCount(int $productId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_product_variations 
                 WHERE product_id = ? AND deleted_at IS NULL";
        $params = [$productId];
        
        // Add filters
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['stock_status'])) {
            $sql .= " AND stock_status = ?";
            $params[] = $filters['stock_status'];
        }
        
        if (isset($filters['featured']) && $filters['featured'] !== null && $filters['featured'] !== '') {
            $sql .= " AND featured = ?";
            $params[] = $filters['featured'];
        }
        
        if (!empty($filters['catalog_visibility'])) {
            $sql .= " AND catalog_visibility = ?";
            $params[] = $filters['catalog_visibility'];
        }
        
        if (isset($filters['manage_stock']) && $filters['manage_stock'] !== null && $filters['manage_stock'] !== '') {
            $sql .= " AND manage_stock = ?";
            $params[] = $filters['manage_stock'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE ? OR sku LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        return (int) $this->db->fetch($sql, ...$params)['count'] ?? 0;
    }

    /**
     * Check if product exists
     */
    public function productExists(int $productId): bool
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_products WHERE id = ? AND deleted_at IS NULL";
        return (int) $this->db->fetch($sql, $productId)['count'] ?? 0 > 0;
    }

    /**
     * Get store ID
     */
    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    /**
     * Set store ID
     */
    public function setStoreId(int $storeId): void
    {
        $this->storeId = $storeId;
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