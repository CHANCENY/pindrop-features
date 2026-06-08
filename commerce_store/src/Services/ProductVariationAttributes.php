<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Services;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class ProductVariationAttributes
{
    protected DatabaseService $db;
    protected LoggerInterface $logger;
    protected ?int $variationId = null;

    public function __construct(DatabaseService $database, LoggerInterface $logger)
    {
        $this->db = $database;
        $this->logger = $logger;
    }

    /**
     * Get attribute by ID
     */
    public function getAttribute(int $attributeId): ?array
    {
        return $this->db->table('commerce_variation_attributes')->where('id','=',$attributeId)->first();
    }

    /**
     * Get attributes by variation ID
     */
    public function getAttributesByVariation(int $variationId): array
    {
        return $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->orderBy('attribute_order')->orderBy('attribute_name')->get();
    }

    /**
     * Get attributes by variation ID and type
     */
    public function getAttributesByVariationAndType(int $variationId, string $type): array
    {
        return $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->where('attribute_type','=',$type)->orderBy('attribute_order')->orderBy('attribute_name')->get();
    }

    /**
     * Get visible attributes by variation ID
     */
    public function getVisibleAttributesByVariation(int $variationId): array
    {
        return $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->where('is_visible','=',1)->orderBy('attribute_order')->orderBy('attribute_name')->get();
    }

    /**
     * Get variation attributes (used for variations)
     */
    public function getVariationAttributes(int $variationId): array
    {
        return $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->where('is_variation','=',1)->orderBy('attribute_order')->orderBy('attribute_name')->get();
    }

    /**
     * Create new attribute
     * @throws DatabaseException
     */
    public function createAttribute(array $data): int
    {
        // Validate required fields
        $this->validateAttributeData($data);

        // Set defaults
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['attribute_type'] = $data['attribute_type'] ?? 'text';
        $data['attribute_order'] = $data['attribute_order'] ?? 0;
        $data['is_visible'] = $data['is_visible'] ?? true;
        $data['is_variation'] = $data['is_variation'] ?? true;

        $sql = "INSERT INTO commerce_variation_attributes (
            variation_id, attribute_name, attribute_value, attribute_type, attribute_order, 
            is_visible, is_variation, created_at, updated_at
        ) VALUES (
            :variation_id, :attribute_name, :attribute_value, :attribute_type, :attribute_order,
            :is_visible, :is_variation, :created_at, :updated_at
        )";

        return $this->db->table('commerce_variation_attributes')->insert($data);
    }

    /**
     * Update attribute
     */
    public function updateAttribute(int $attributeId, array $data): bool
    {
        // Validate required fields
        $this->validateAttributeData($data);

        // Set updated timestamp
        $data['updated_at'] = date('Y-m-d H:i:s');

        $data['id'] = $attributeId;

        $id = $data['id']; unset($data['id']);
        $this->db->table('commerce_variation_attributes')->where('id','=',$id)->update($data);
        return true;
    }

    /**
     * Delete attribute
     */
    public function deleteAttribute(int $attributeId): bool
    {
        $this->db->table('commerce_variation_attributes')->where('id','=',$attributeId)->delete();
        return true;
    }

    /**
     * Delete attributes by variation ID
     */
    public function deleteAttributesByVariation(int $variationId): bool
    {
        $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->delete();
        return true;
    }

    /**
     * Get attribute count by variation
     */
    public function getAttributeCount(int $variationId): int
    {
        return $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->count();
    }

    /**
     * Get attribute count by variation and type
     */
    public function getAttributeCountByVariationAndType(int $variationId, string $type): int
    {
        return $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->where('attribute_type','=',$type)->count();
    }

    /**
     * Update attribute order
     */
    public function updateAttributeOrder(int $attributeId, int $order): bool
    {
        $this->db->table('commerce_variation_attributes')->where('id','=',$attributeId)->update(['attribute_order'=>$order,'updated_at'=>date('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * Bulk update attribute orders
     */
    public function updateAttributeOrders(array $attributeOrders): bool
    {
        $this->db->beginTransaction();
        try {
            foreach ($attributeOrders as $aid => $ord) {
                $this->db->table('commerce_variation_attributes')->where('id','=',$aid)->update(['attribute_order'=>(int)$ord,'updated_at'=>date('Y-m-d H:i:s')]);
            }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollback(); return false; }
        return true;
    }

    /**
     * Get attribute by name and variation
     */
    public function getAttributeByNameAndVariation(int $variationId, string $attributeName): ?array
    {
        return $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->where('attribute_name','=',$attributeName)->orderBy('attribute_order')->first();
    }

    /**
     * Check if attribute exists
     */
    public function attributeExists(int $attributeId): bool
    {
        return $this->db->table('commerce_variation_attributes')->where('id','=',$attributeId)->exists();
    }

    /**
     * Check if attribute name exists for variation
     */
    public function attributeNameExists(int $variationId, string $attributeName): bool
    {
        return $this->db->table('commerce_variation_attributes')->where('variation_id','=',$variationId)->where('attribute_name','=',$attributeName)->exists();
    }

    /**
     * Get unique attribute names by variation
     */
    public function getUniqueAttributeNames(int $variationId): array
    {
        return $this->db->table('commerce_variation_attributes')->select(['attribute_name'])->where('variation_id','=',$variationId)->orderBy('attribute_name')->pluck('attribute_name');
    }

    /**
     * Get attributes by type
     */
    public function getAttributesByType(string $type): array
    {
        return $this->db->table('commerce_variation_attributes')->where('attribute_type','=',$type)->orderBy('attribute_name')->get();
    }

    /**
     * Copy attributes from one variation to another
     */
    public function copyAttributes(int $sourceVariationId, int $targetVariationId): bool
    {
        // Get source attributes
        $sourceAttributes = $this->getAttributesByVariation($sourceVariationId);
        if (empty($sourceAttributes)) {
            return false;
        }

        // Delete existing target attributes
        $this->deleteAttributesByVariation($targetVariationId);

        // Copy attributes to target
        foreach ($sourceAttributes as $attribute) {
            unset($attribute['id'], $attribute['created_at'], $attribute['updated_at']);
            $attribute['variation_id'] = $targetVariationId;
            $this->createAttribute($attribute);
        }

        return true;
    }

    /**
     * Validate attribute data
     */
    protected function validateAttributeData(array $data): void
    {
        $required = ['variation_id', 'attribute_name', 'attribute_value'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Validate attribute type
        if (isset($data['attribute_type'])) {
            $validTypes = ['text', 'number', 'boolean', 'select', 'multiselect'];
            if (!in_array($data['attribute_type'], $validTypes)) {
                throw new \InvalidArgumentException("Invalid attribute type: {$data['attribute_type']}");
            }
        }

        // Validate order
        if (isset($data['attribute_order']) && $data['attribute_order'] < 0) {
            throw new \InvalidArgumentException("Attribute order cannot be negative");
        }
    }

    /**
     * Get variation ID
     */
    public function getVariationId(): ?int
    {
        return $this->variationId;
    }

    /**
     * Set variation ID
     */
    public function setVariationId(int $variationId): void
    {
        $this->variationId = $variationId;
    }

    /**
     * Get formatted attributes for display
     */
    public function getFormattedAttributes(int $variationId): array
    {
        $attributes = $this->getAttributesByVariation($variationId);
        $formatted = [];

        foreach ($attributes as $attribute) {
            $formatted[$attribute['attribute_name']] = [
                'id' => $attribute['id'],
                'name' => $attribute['attribute_name'],
                'value' => $attribute['attribute_value'],
                'type' => $attribute['attribute_type'],
                'order' => $attribute['attribute_order'],
                'visible' => (bool) $attribute['is_visible'],
                'variation' => (bool) $attribute['is_variation'],
                'created_at' => $attribute['created_at'],
                'updated_at' => $attribute['updated_at']
            ];
        }

        return $formatted;
    }

    /**
     * Search attributes by value
     */
    public function searchAttributes(string $query, int $limit = 20): array
    {
    
        $searchTerm = "%{$query}%";

        return $this->db->table('commerce_variation_attributes')->whereRaw('attribute_value LIKE ? OR attribute_name LIKE ?',[$searchTerm,$searchTerm])->orderBy('attribute_name')->limit($limit)->get();
    }

    /**
     * Get attributes for filtering
     */
    public function getAttributesForFiltering(int $variationId): array
    {
        return $this->db->table('commerce_variation_attributes')->select(["attribute_name","attribute_type","COUNT(*) as attribute_count","GROUP_CONCAT(DISTINCT attribute_value ORDER BY attribute_order ASC SEPARATOR '|') as attribute_values"])->where('variation_id','=',$variationId)->where('is_visible','=',1)->groupBy('attribute_name','attribute_type')->orderBy('attribute_order')->get();
    }
}
