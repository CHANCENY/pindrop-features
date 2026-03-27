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
        $sql = "SELECT * FROM commerce_variation_attributes WHERE id = ?";
        return $this->db->fetchRow($sql, [$attributeId]);
    }

    /**
     * Get attributes by variation ID
     */
    public function getAttributesByVariation(int $variationId): array
    {
        $sql = "SELECT * FROM commerce_variation_attributes 
                 WHERE variation_id = ? ORDER BY attribute_order ASC, attribute_name ASC";
        return $this->db->fetchAll($sql, [$variationId]);
    }

    /**
     * Get attributes by variation ID and type
     */
    public function getAttributesByVariationAndType(int $variationId, string $type): array
    {
        $sql = "SELECT * FROM commerce_variation_attributes 
                 WHERE variation_id = ? AND attribute_type = ? ORDER BY attribute_order ASC, attribute_name ASC";
        return $this->db->fetchAll($sql, [$variationId, $type]);
    }

    /**
     * Get visible attributes by variation ID
     */
    public function getVisibleAttributesByVariation(int $variationId): array
    {
        $sql = "SELECT * FROM commerce_variation_attributes 
                 WHERE variation_id = ? AND is_visible = 1 ORDER BY attribute_order ASC, attribute_name ASC";
        return $this->db->fetchAll($sql, [$variationId]);
    }

    /**
     * Get variation attributes (used for variations)
     */
    public function getVariationAttributes(int $variationId): array
    {
        $sql = "SELECT * FROM commerce_variation_attributes 
                 WHERE variation_id = ? AND is_variation = 1 ORDER BY attribute_order ASC, attribute_name ASC";
        return $this->db->fetchAll($sql, [$variationId]);
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

        $this->db->query($sql, ...$data);
        return $this->db->lastInsertId();
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

        $sql = "UPDATE commerce_variation_attributes SET 
            variation_id = :variation_id, attribute_name = :attribute_name, attribute_value = :attribute_value,
            attribute_type = :attribute_type, attribute_order = :attribute_order, is_visible = :is_visible,
            is_variation = :is_variation, updated_at = :updated_at
        WHERE id = :id";

        $this->db->query($sql, $data);
        return true;
    }

    /**
     * Delete attribute
     */
    public function deleteAttribute(int $attributeId): bool
    {
        $sql = "DELETE FROM commerce_variation_attributes WHERE id = ?";
        $this->db->query($sql, [$attributeId]);
        return true;
    }

    /**
     * Delete attributes by variation ID
     */
    public function deleteAttributesByVariation(int $variationId): bool
    {
        $sql = "DELETE FROM commerce_variation_attributes WHERE variation_id = ?";
        $this->db->query($sql, [$variationId]);
        return true;
    }

    /**
     * Get attribute count by variation
     */
    public function getAttributeCount(int $variationId): int
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_variation_attributes WHERE variation_id = ?";
        $result = $this->db->fetch($sql, [$variationId]);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Get attribute count by variation and type
     */
    public function getAttributeCountByVariationAndType(int $variationId, string $type): int
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_variation_attributes WHERE variation_id = ? AND attribute_type = ?";
        $result = $this->db->fetch($sql, [$variationId, $type]);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Update attribute order
     */
    public function updateAttributeOrder(int $attributeId, int $order): bool
    {
        $sql = "UPDATE commerce_variation_attributes SET attribute_order = ?, updated_at = ? WHERE id = ?";
        $this->db->query($sql, [$order, date('Y-m-d H:i:s'), $attributeId]);
        return true;
    }

    /**
     * Bulk update attribute orders
     */
    public function updateAttributeOrders(array $attributeOrders): bool
    {
        $sql = "UPDATE commerce_variation_attributes SET attribute_order = CASE id ";
        foreach ($attributeOrders as $attributeId => $order) {
            $sql .= "WHEN {$attributeId} THEN {$order} ";
        }
        $sql .= "END, updated_at = ? WHERE id IN (";
        $sql .= implode(',', array_keys($attributeOrders)) . ")";

        $this->db->query($sql, [date('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * Get attribute by name and variation
     */
    public function getAttributeByNameAndVariation(int $variationId, string $attributeName): ?array
    {
        $sql = "SELECT * FROM commerce_variation_attributes 
                 WHERE variation_id = ? AND attribute_name = ? ORDER BY attribute_order ASC";
        return $this->db->fetch($sql, [$variationId, $attributeName]);
    }

    /**
     * Check if attribute exists
     */
    public function attributeExists(int $attributeId): bool
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_variation_attributes WHERE id = ?";
        $result = $this->db->fetch($sql, [$attributeId]);
        return (int) ($result['count'] ?? 0) > 0;
    }

    /**
     * Check if attribute name exists for variation
     */
    public function attributeNameExists(int $variationId, string $attributeName): bool
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_variation_attributes WHERE variation_id = ? AND attribute_name = ?";
        $result = $this->db->fetch($sql, [$variationId, $attributeName]);
        return (int) ($result['count'] ?? 0) > 0;
    }

    /**
     * Get unique attribute names by variation
     */
    public function getUniqueAttributeNames(int $variationId): array
    {
        $sql = "SELECT DISTINCT attribute_name FROM commerce_variation_attributes WHERE variation_id = ? ORDER BY attribute_name ASC";
        return $this->db->fetchAll($sql, [$variationId]);
    }

    /**
     * Get attributes by type
     */
    public function getAttributesByType(string $type): array
    {
        $sql = "SELECT * FROM commerce_variation_attributes WHERE attribute_type = ? ORDER BY attribute_name ASC";
        return $this->db->fetchAll($sql, [$type]);
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
        $sql = "SELECT * FROM commerce_variation_attributes 
                 WHERE attribute_value LIKE ? OR attribute_name LIKE ? 
                 ORDER BY attribute_name ASC 
                 LIMIT ?";
        $searchTerm = "%{$query}%";

        return $this->db->fetchAll($sql, [$searchTerm, $searchTerm, $limit]);
    }

    /**
     * Get attributes for filtering
     */
    public function getAttributesForFiltering(int $variationId): array
    {
        $sql = "SELECT 
            attribute_name, 
            attribute_type, 
            COUNT(*) as attribute_count,
            GROUP_CONCAT(DISTINCT attribute_value ORDER BY attribute_order ASC SEPARATOR '|') as attribute_values
        FROM commerce_variation_attributes 
        WHERE variation_id = ? AND is_visible = 1 
        GROUP BY attribute_name, attribute_type 
        ORDER BY attribute_order ASC";

        return $this->db->fetchAll($sql, [$variationId]);
    }
}
