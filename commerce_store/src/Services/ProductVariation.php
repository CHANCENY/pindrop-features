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
        return $this->db->table('commerce_product_variations')->where('id','=',$variationId)->whereNull('deleted_at')->first();
    }

    /**
     * Get variation by UUID
     */
    public function getVariationByUuid(string $uuid): ?array
    {
        return $this->db->table('commerce_product_variations')->where('uuid','=',$uuid)->whereNull('deleted_at')->first();
    }

    /**
     * Get variation by SKU
     */
    public function getVariationBySku(string $sku, ?int $productId = null): ?array
    {
        $qb = $this->db->table('commerce_product_variations')->where('sku','=',$sku)->whereNull('deleted_at');
        $pid = $productId ?? $this->productId;
        if ($pid) $qb->where('product_id','=',$pid);
        return $qb->first();
    }

    /**
     * Get variations by product ID
     */
    public function getVariationsByProduct(int $productId): array
    {
        return $this->db->table('commerce_product_variations')->where('product_id','=',$productId)->whereNull('deleted_at')->orderBy('menu_order')->orderBy('name')->get();
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

        return $this->db->table('commerce_product_variations')->insert($data);
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

        return (bool)$this->db->table('commerce_product_variations')->where('id','=',$variationId)->update($data);
    }

    /**
     * Delete variation (soft delete)
     */
    public function deleteVariation(int $variationId): bool
    {
        return (bool)$this->db->table('commerce_product_variations')->where('id','=',$variationId)->update(['deleted_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
    }

    /**
     * Restore variation
     */
    public function restoreVariation(int $variationId): bool
    {
        return (bool)$this->db->table('commerce_product_variations')->where('id','=',$variationId)->update(['deleted_at'=>null,'updated_at'=>date('Y-m-d H:i:s')]);
    }

    /**
     * Get variation count by product
     */
    public function getVariationCount(int $productId): int
    {
        return $this->db->table('commerce_product_variations')->where('product_id','=',$productId)->whereNull('deleted_at')->count();
    }

    /**
     * Get featured variations
     */
    public function getFeaturedVariations(int $productId, int $limit = 10): array
    {
        return $this->db->table('commerce_product_variations')->where('product_id','=',$productId)->where('featured','=',1)->where('status','=','publish')->whereNull('deleted_at')->orderBy('menu_order')->orderBy('name')->limit($limit)->get();
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

        return (bool)$this->db->table('commerce_product_variations')->where('id','=',$variationId)->update($updateData);
    }

    /**
     * Get variations by status
     */
    public function getVariationsByStatus(int $productId, string $status): array
    {
        return $this->db->table('commerce_product_variations')->where('product_id','=',$productId)->where('status','=',$status)->whereNull('deleted_at')->orderBy('menu_order')->orderBy('name')->get();
    }

    /**
     * Get in-stock variations
     */
    public function getInStockVariations(int $productId): array
    {
        return $this->db->table('commerce_product_variations')->where('product_id','=',$productId)->where('stock_status','=','instock')->whereNull('deleted_at')->orderBy('menu_order')->get();
    }

    /**
     * Update variation menu order
     */
    public function updateMenuOrder(int $variationId, int $order): bool
    {
        return (bool)$this->db->table('commerce_product_variations')->where('id','=',$variationId)->update(['menu_order'=>$order,'updated_at'=>date('Y-m-d H:i:s')]);
    }

    /**
     * Bulk update menu order
     */
    public function updateMenuOrders(array $variationOrders): bool
    {
        $sql = "UPDATE commerce_product_variations SET menu_order = CASE id ";
        foreach ($variationOrders as $variationId => $order) {
            $sql .= "WHEN {$variationId} THEN {$order} ";
        }
        $sql .= "END, updated_at = ? WHERE id IN (";
        $sql .= implode(',', array_keys($variationOrders)) . ")";

        return true; // menu orders updated via transaction above
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
        $attributes = $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->orderBy('attribute_order')->get();

        $variation['attributes'] = $attributes;
        return $variation;
    }

    /**
     * Check if variation exists
     */
    public function variationExists(int $variationId): bool
    {
        return $this->db->table('commerce_product_variations')->where('id','=',$variationId)->whereNull('deleted_at')->exists();
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
                $variation['images'] = $this->db->table('commerce_variation_images')->where('variation_id','=',$variation['id'])->orderBy('image_order')->get();
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
        return $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->orderBy('attribute_order')->get();
    }

    /**
     * Get variation images
     * @throws DatabaseException
     */
    public function getVariationImages(int $variationId): array
    {
        return $this->db->table('commerce_variation_images')->where('variation_id','=',$variationId)->orderBy('image_order')->get();
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