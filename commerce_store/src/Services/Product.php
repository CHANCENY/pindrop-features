<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Services;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;


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
        return $this->db->table('commerce_products')->where('id','=',$productId)->whereNull('deleted_at')->first();
    }

    /**
     * Get product by UUID
     */
    public function getProductByUuid(string $uuid): ?array
    {
        return $this->db->table('commerce_products')->where('uuid','=',$uuid)->whereNull('deleted_at')->first();
    }

    /**
     * Get product by SKU
     */
    public function getProductBySku(string $sku, ?int $storeId = null): ?array
    {
        $qb = $this->db->table('commerce_products')->where('sku','=',$sku)->whereNull('deleted_at');
        $sid = $storeId ?? $this->storeId;
        if ($sid) $qb->where('store_id','=',$sid);
        return $qb->first();
    }

    /**
     * Get product by slug
     */
    public function getProductBySlug(string $slug, ?int $storeId = null): ?array
    {
        $qb = $this->db->table('commerce_products')->where('slug','=',$slug)->whereNull('deleted_at');
        $sid = $storeId ?? $this->storeId;
        if ($sid) $qb->where('store_id','=',$sid);
        return $qb->first();
    }

    /**
     * Get products by store ID
     */
    public function getProductsByStore(int $storeId, array $filters = []): array
    {
        $qb = $this->db->table('commerce_products')->where('store_id','=',$storeId)->whereNull('deleted_at');
        if (!empty($filters['status']))             $qb->where('status','=',$filters['status']);
        if (!empty($filters['type']))               $qb->where('type','=',$filters['type']);
        if (!empty($filters['featured']))           $qb->where('featured','=',$filters['featured']);
        if (!empty($filters['catalog_visibility'])) $qb->where('catalog_visibility','=',$filters['catalog_visibility']);
        if (!empty($filters['stock_status']))       $qb->where('stock_status','=',$filters['stock_status']);
        if (!empty($filters['category']))           $qb->whereRaw('JSON_CONTAINS(categories, ?)',[json_encode($filters['category'])]);
        if (!empty($filters['search']))             $qb->whereRaw('MATCH(name, description, short_description) AGAINST(? IN NATURAL LANGUAGE MODE)',[$filters['search']]);
        if (!empty($filters['order_by']))           $qb->whereRaw('1=1'); // handled below
        $qb->orderBy('menu_order')->orderBy('name');
        if (!empty($filters['limit']))  $qb->limit((int)$filters['limit']);
        if (!empty($filters['offset'])) $qb->offset((int)$filters['offset']);
        return $qb->get();
    }

    /**
     * Get total count of products for a store with filters
     */
    public function getProductsCountByStore(int $storeId, array $filters = []): int
    {
        
        $qb = $this->db->table('commerce_products')->where('store_id','=',$storeId)->whereNull('deleted_at');
        if (!empty($filters['status']))             $qb->where('status','=',$filters['status']);
        if (!empty($filters['type']))               $qb->where('type','=',$filters['type']);
        if (!empty($filters['featured']))           $qb->where('featured','=',$filters['featured']);
        if (!empty($filters['catalog_visibility'])) $qb->where('catalog_visibility','=',$filters['catalog_visibility']);
        if (!empty($filters['stock_status']))       $qb->where('stock_status','=',$filters['stock_status']);
        if (!empty($filters['category']))           $qb->whereRaw('JSON_CONTAINS(categories, ?)',[json_encode($filters['category'])]);
        if (!empty($filters['search']))             $qb->whereRaw('MATCH(name, description, short_description) AGAINST(? IN NATURAL LANGUAGE MODE)',[$filters['search']]);
        return $qb->count();
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

        return $this->db->table('commerce_product_variations')->insert($data);
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

        return (bool)$this->db->table('commerce_product_variations')->where('id','=',$variationId)->update($data);
    }

    /**
     * Delete product variation (soft delete)
     */
    public function deleteVariation(int $variationId): bool
    {
        return (bool)$this->db->table('commerce_product_variations')->where('id','=',$variationId)->update(['deleted_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
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

        return $this->db->table('commerce_products')->insert($data);
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

        return (bool)$this->db->table('commerce_products')->where('id','=',$productId)->update($data);
    }

    /**
     * Delete product (soft delete)
     */
    public function deleteProduct(int $productId): bool
    {
        return (bool)$this->db->table('commerce_products')->where('id','=',$productId)->update(['deleted_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
    }

    /**
     * Restore product
     */
    public function restoreProduct(int $productId): bool
    {
        return (bool)$this->db->table('commerce_products')->where('id','=',$productId)->update(['deleted_at'=>null,'updated_at'=>date('Y-m-d H:i:s')]);
    }

    /**
     * Get product count by store
     */
    public function getProductCount(int $storeId, array $filters = []): int
    {
        $qb = $this->db->table('commerce_products')->where('store_id','=',$storeId)->whereNull('deleted_at');
        if (!empty($filters['status']))      $qb->where('status','=',$filters['status']);
        if (!empty($filters['type']))        $qb->where('type','=',$filters['type']);
        if (!empty($filters['featured']))    $qb->where('featured','=',$filters['featured']);
        if (!empty($filters['stock_status'])) $qb->where('stock_status','=',$filters['stock_status']);
        return $qb->count();
    }

    /**
     * Get featured products
     */
    public function getFeaturedProducts(int $storeId, int $limit = 10): array
    {
        return $this->db->table('commerce_products')->where('store_id','=',$storeId)->where('featured','=',1)->where('status','=','publish')->whereNull('deleted_at')->orderBy('menu_order')->orderBy('name')->limit($limit)->get();
    }

    /**
     * Get products by category
     */
    public function getProductsByCategory(int $storeId, string $category, int $limit = 20): array
    {
        return $this->db->table('commerce_products')->where('store_id','=',$storeId)->whereRaw('JSON_CONTAINS(categories, ?)',[json_encode($category)])->where('status','=','publish')->whereNull('deleted_at')->orderBy('menu_order')->orderBy('name')->limit($limit)->get();
    }

    /**
     * Search products
     */
    public function searchProducts(int $storeId, string $query, int $limit = 20): array
    {
        
        return $this->db->table('commerce_products')->where('store_id','=',$storeId)->whereRaw('MATCH(name, description, short_description) AGAINST(? IN NATURAL LANGUAGE MODE)',[$query])->where('status','=','publish')->whereIn('catalog_visibility',['visible','catalog'])->whereNull('deleted_at')->orderBy('total_sales','DESC')->orderBy('name')->limit($limit)->get();
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

        return $this->db->table('commerce_products')->whereIn('id',$relatedIds)->where('status','=','publish')->whereNull('deleted_at')->orderBy('total_sales','DESC')->orderBy('name')->limit($limit)->get();
    }

    /**
     * Update stock
     */
    public function updateStock(int $productId, int $quantity, ?string $status = null): bool
    {
        return (bool)$this->db->table('commerce_products')->where('id','=',$productId)->update(['stock_quantity'=>$quantity,'updated_at'=>date('Y-m-d H:i:s'),'stock_status'=>$status]);
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
        
        $queryBuilder = $this->db->table('commerce_product_variations');
        $queryBuilder->where('product_id', '=', $productId);
    
        // Add filters
        if (!empty($options['status'])) {
            $queryBuilder->where('status','=', $options['status']);
        }
        
        if (!empty($options['stock_status'])) {
            $queryBuilder->where('stock_status', '=', $options['stock_status']);
        }
        
        if (isset($options['featured']) && $options['featured'] !== null && $options['featured'] !== '') {
            $queryBuilder->where('featured', '=', $options['featured']);
        }
        
        if (!empty($options['catalog_visibility'])) {
            $queryBuilder->where('catalog_visibility', '=', $options['catalog_visibility']);
        }
        
        if (isset($options['manage_stock']) && $options['manage_stock'] !== null && $options['manage_stock'] !== '') {
            $queryBuilder->where('manage_stock','=', $options['manage_stock']);
        }
        
        if (!empty($options['search'])) {
            $searchTerm = '%' . $options['search'] . '%';
            $queryBuilder->whereRaw(" (name LIKE ? OR sku LIKE ?)",[$searchTerm, $searchTerm]);
        }
        
        return $queryBuilder->whereNull('deleted_at')->orderBy('menu_order','DESC')->orderBy('name')->limit($limit)->get();
    }

    /**
     * Get product variations count
     */
    public function getProductVariationsCount(int $productId, array $filters = []): int
    {
       
        $qb = $this->db->table('commerce_product_variations')->where('product_id','=',$productId)->whereNull('deleted_at');
        if (!empty($filters['status']))             $qb->where('status','=',$filters['status']);
        if (!empty($filters['stock_status']))       $qb->where('stock_status','=',$filters['stock_status']);
        if (!empty($filters['catalog_visibility'])) $qb->where('catalog_visibility','=',$filters['catalog_visibility']);
        if (isset($filters['featured']) && $filters['featured'] !== null && $filters['featured'] !== '') $qb->where('featured','=',$filters['featured']);
        if (isset($filters['manage_stock']) && $filters['manage_stock'] !== null && $filters['manage_stock'] !== '') $qb->where('manage_stock','=',$filters['manage_stock']);
        if (!empty($filters['search'])) $qb->whereRaw('(name LIKE ? OR sku LIKE ?)',['%'.$filters['search'].'%','%'.$filters['search'].'%']);
        return $qb->count();
    }

    /**
     * Check if product exists
     */
    public function productExists(int $productId): bool
    {
        return $this->db->table('commerce_products')->where('id','=',$productId)->whereNull('deleted_at')->exists();
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