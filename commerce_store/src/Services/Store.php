<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Services;

use Exception;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Store
{
    protected DatabaseService $database;

    public function __construct(DatabaseService $database)
    {
        $this->database = $database;
    }

    /**
     * Get store by ID
     */
    public function getStore(int $storeId): ?array
    {
        try {
            $query = "SELECT * FROM commerce_stores WHERE id = :id AND deleted_at IS NULL";
            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute(['id' => $storeId]);
            return $stmt->fetch() ?: null;
        } catch (DatabaseException $e) {
            throw new Exception("Failed to fetch store: " . $e->getMessage());
        }
    }

    /**
     * Get store by user ID
     */
    public function getStoreByUserId(int $userId): ?array
    {
        try {
            $query = "SELECT * FROM commerce_stores WHERE user_id = :user_id AND deleted_at IS NULL";
            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute(['user_id' => $userId]);
            return $stmt->fetch() ?: null;
        } catch (DatabaseException $e) {
            throw new Exception("Failed to fetch store by user: " . $e->getMessage());
        }
    }

    /**
     * Get store by slug
     */
    public function getStoreBySlug(string $slug): ?array
    {
        try {
            $query = "SELECT * FROM commerce_stores WHERE store_slug = :slug AND deleted_at IS NULL";
            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute(['slug' => $slug]);
            return $stmt->fetch() ?: null;
        } catch (DatabaseException $e) {
            throw new Exception("Failed to fetch store by slug: " . $e->getMessage());
        }
    }

    /**
     * Create new store
     */
    public function createStore(array $data): int
    {
        try {
            // Generate UUID
            $data['uuid'] = $this->generateUuid();
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');

            // Validate required fields
            $this->validateStoreData($data);

            // Generate slug if not provided
            if (empty($data['store_slug'])) {
                $data['store_slug'] = $this->generateSlug($data['store_name']);
            }

            // Ensure unique slug
            $data['store_slug'] = $this->ensureUniqueSlug($data['store_slug']);

            // Handle JSON fields
            $jsonFields = ['settings', 'social_links', 'business_hours', 'shipping_policies', 'return_policies', 'payment_methods', 'metadata'];
            foreach ($jsonFields as $field) {
                if (isset($data[$field]) && is_array($data[$field])) {
                    $data[$field] = json_encode($data[$field]);
                }
            }

            // Handle file uploads
            if (isset($data['store_logo']) && $data['store_logo'] instanceof UploadedFile) {
                $data['store_logo_url'] = $this->handleFileUpload($data['store_logo'], 'logos');
                unset($data['store_logo']);
            }

            if (isset($data['store_banner']) && $data['store_banner'] instanceof UploadedFile) {
                $data['store_banner_url'] = $this->handleFileUpload($data['store_banner'], 'banners');
                unset($data['store_banner']);
            }

            // Build query
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            
            $query = "INSERT INTO commerce_stores ($columns) VALUES ($placeholders)";
            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute($data);

            return (int) $this->database->lastInsertId();
        } catch (DatabaseException $e) {
            throw new Exception("Failed to create store: " . $e->getMessage());
        }
    }

    /**
     * Update store settings
     */
    public function updateStore(int $storeId, array $data): bool
    {
        try {
            $store = $this->getStore($storeId);
            if (!$store) {
                throw new Exception("Store not found");
            }

            $data['updated_at'] = date('Y-m-d H:i:s');

            // Validate store data
            $this->validateStoreData($data, $storeId);

            // Generate slug if name changed
            if (isset($data['store_name']) && $data['store_name'] !== $store['store_name']) {
                if (empty($data['store_slug'])) {
                    $data['store_slug'] = $this->generateSlug($data['store_name']);
                }
                $data['store_slug'] = $this->ensureUniqueSlug($data['store_slug'], $storeId);
            }

            // Handle JSON fields
            $jsonFields = ['settings', 'social_links', 'business_hours', 'shipping_policies', 'return_policies', 'payment_methods', 'metadata'];
            foreach ($jsonFields as $field) {
                if (isset($data[$field]) && is_array($data[$field])) {
                    $data[$field] = json_encode($data[$field]);
                }
            }

            // Handle file uploads
            if (isset($data['store_logo']) && $data['store_logo'] instanceof UploadedFile) {
                $data['store_logo_url'] = $this->handleFileUpload($data['store_logo'], 'logos');
                unset($data['store_logo']);
            }

            if (isset($data['store_banner']) && $data['store_banner'] instanceof UploadedFile) {
                $data['store_banner_url'] = $this->handleFileUpload($data['store_banner'], 'banners');
                unset($data['store_banner']);
            }

            // Build SET clause
            $setClause = [];
            foreach ($data as $key => $value) {
                $setClause[] = "$key = :$key";
            }
            $setClause = implode(', ', $setClause);

            $query = "UPDATE commerce_stores SET $setClause WHERE id = :id";
            $data['id'] = $storeId;
            
            $stmt = $this->database->getPdo()->prepare($query);
            return $stmt->execute($data);
        } catch (DatabaseException $e) {
            throw new Exception("Failed to update store: " . $e->getMessage());
        }
    }

    /**
     * Delete store (soft delete)
     */
    public function deleteStore(int $storeId): bool
    {
        try {
            $query = "UPDATE commerce_stores SET deleted_at = :deleted_at WHERE id = :id";
            $stmt = $this->database->getPdo()->prepare($query);
            return $stmt->execute([
                'id' => $storeId,
                'deleted_at' => date('Y-m-d H:i:s')
            ]);
        } catch (DatabaseException $e) {
            throw new Exception("Failed to delete store: " . $e->getMessage());
        }
    }

    /**
     * Get store categories
     */
    public function getStoreCategories(int $storeId): array
    {
        try {
            $query = "SELECT * FROM commerce_store_categories WHERE store_id = :store_id AND deleted_at IS NULL ORDER BY sort_order, category_name";
            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute(['store_id' => $storeId]);
            return $stmt->fetchAll();
        } catch (DatabaseException $e) {
            throw new Exception("Failed to fetch store categories: " . $e->getMessage());
        }
    }

    /**
     * Add store category
     */
    public function addCategory(int $storeId, array $data): int
    {
        try {
            $data['store_id'] = $storeId;
            $data['uuid'] = $this->generateUuid();
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');

            // Generate slug if not provided
            if (empty($data['category_slug'])) {
                $data['category_slug'] = $this->generateSlug($data['category_name']);
            }

            // Ensure unique slug within store
            $data['category_slug'] = $this->ensureUniqueCategorySlug($storeId, $data['category_slug']);

            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            
            $query = "INSERT INTO commerce_store_categories ($columns) VALUES ($placeholders)";
            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute($data);

            return (int) $this->database->lastInsertId();
        } catch (DatabaseException $e) {
            throw new Exception("Failed to add category: " . $e->getMessage());
        }
    }

    /**
     * Get store staff
     */
    public function getStoreStaff(int $storeId): array
    {
        try {
            $query = "SELECT ss.*, u.username, u.email, u.first_name, u.last_name 
                     FROM commerce_store_staff ss 
                     JOIN users u ON ss.user_id = u.id 
                     WHERE ss.store_id = :store_id AND ss.deleted_at IS NULL 
                     ORDER BY ss.role, ss.created_at";
            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute(['store_id' => $storeId]);
            return $stmt->fetchAll();
        } catch (DatabaseException $e) {
            throw new Exception("Failed to fetch store staff: " . $e->getMessage());
        }
    }

    /**
     * Add staff member
     */
    public function addStaff(int $storeId, int $userId, string $role, array $permissions = []): int
    {
        try {
            $data = [
                'store_id' => $storeId,
                'user_id' => $userId,
                'role' => $role,
                'permissions' => json_encode($permissions),
                'uuid' => $this->generateUuid(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            
            $query = "INSERT INTO commerce_store_staff ($columns) VALUES ($placeholders)";
            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute($data);

            return (int) $this->database->lastInsertId();
        } catch (DatabaseException $e) {
            throw new Exception("Failed to add staff: " . $e->getMessage());
        }
    }

    /**
     * Update store statistics
     */
    public function updateStoreStats(int $storeId, array $stats): bool
    {
        try {
            $setClause = [];
            $data = ['id' => $storeId, 'updated_at' => date('Y-m-d H:i:s')];

            foreach ($stats as $key => $value) {
                if (in_array($key, ['total_sales', 'total_orders', 'rating_count', 'rating_average'])) {
                    $setClause[] = "$key = :$key";
                    $data[$key] = $value;
                }
            }

            if (empty($setClause)) {
                return true;
            }

            $setClause[] = "updated_at = :updated_at";
            $query = "UPDATE commerce_stores SET " . implode(', ', $setClause) . " WHERE id = :id";
            
            $stmt = $this->database->getPdo()->prepare($query);
            return $stmt->execute($data);
        } catch (DatabaseException $e) {
            throw new Exception("Failed to update store stats: " . $e->getMessage());
        }
    }

    /**
     * Validate store data
     */
    protected function validateStoreData(array $data, ?int $excludeId = null): void
    {
        // Required fields for new store
        if ($excludeId === null) {
            $required = ['user_id', 'store_name', 'business_type'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Required field '$field' is missing");
                }
            }
        }

        // Validate email format
        if (!empty($data['store_email']) && !filter_var($data['store_email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid store email format");
        }

        // Validate URL format
        if (!empty($data['store_website']) && !filter_var($data['store_website'], FILTER_VALIDATE_URL)) {
            throw new Exception("Invalid store website URL");
        }

        // Validate business type
        if (!empty($data['business_type'])) {
            $validTypes = ['individual', 'company', 'partnership', 'corporation', 'non_profit'];
            if (!in_array($data['business_type'], $validTypes)) {
                throw new Exception("Invalid business type");
            }
        }

        // Validate store status
        if (!empty($data['store_status'])) {
            $validStatuses = ['active', 'inactive', 'suspended', 'pending_approval', 'rejected', 'closed'];
            if (!in_array($data['store_status'], $validStatuses)) {
                throw new Exception("Invalid store status");
            }
        }

        // Validate currency
        if (!empty($data['currency'])) {
            if (!preg_match('/^[A-Z]{3}$/', $data['currency'])) {
                throw new Exception("Invalid currency format");
            }
        }

        // Validate commission rate
        if (isset($data['commission_rate'])) {
            $rate = (float) $data['commission_rate'];
            if ($rate < 0 || $rate > 1) {
                throw new Exception("Commission rate must be between 0 and 1");
            }
        }
    }

    /**
     * Generate UUID
     */
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

    /**
     * Generate URL-friendly slug
     */
    protected function generateSlug(string $text): string
    {
        $slug = strtolower($text);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug);
        return $slug;
    }

    /**
     * Ensure unique store slug
     */
    protected function ensureUniqueSlug(string $slug, ?int $excludeId = null): string
    {
        $originalSlug = $slug;
        $counter = 1;

        while (true) {
            $query = "SELECT id FROM commerce_stores WHERE store_slug = :slug AND deleted_at IS NULL";
            $params = ['slug' => $slug];

            if ($excludeId) {
                $query .= " AND id != :exclude_id";
                $params['exclude_id'] = $excludeId;
            }

            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute($params);
            $existing = $stmt->fetch();

            if (!$existing) {
                break;
            }

            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Ensure unique category slug within store
     */
    protected function ensureUniqueCategorySlug(int $storeId, string $slug, ?int $excludeId = null): string
    {
        $originalSlug = $slug;
        $counter = 1;

        while (true) {
            $query = "SELECT id FROM commerce_store_categories WHERE store_id = :store_id AND category_slug = :slug AND deleted_at IS NULL";
            $params = ['store_id' => $storeId, 'slug' => $slug];

            if ($excludeId) {
                $query .= " AND id != :exclude_id";
                $params['exclude_id'] = $excludeId;
            }

            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute($params);
            $existing = $stmt->fetch();

            if (!$existing) {
                break;
            }

            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Handle file upload
     */
    protected function handleFileUpload(UploadedFile $file, string $directory): string
    {
        $uploadDir = "public://$directory";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = uniqid() . '.' . $file->getClientOriginalExtension();

        /**@var File $file **/
        $file = $file->move($uploadDir, $filename);

        return $file->getPath(). DIRECTORY_SEPARATOR . $file->getFilename();
    }

    /**
     * Check if user owns store
     */
    public function userOwnsStore(int $userId, int $storeId): bool
    {
        try {
            $query = "SELECT id FROM commerce_stores WHERE id = :store_id AND user_id = :user_id AND deleted_at IS NULL";
            $stmt = $this->database->getPdo()->prepare($query);
            $stmt->execute(['store_id' => $storeId, 'user_id' => $userId]);
            return (bool) $stmt->fetch();
        } catch (DatabaseException $e) {
            return false;
        }
    }

    /**
     * Get store settings as array
     */
    public function getStoreSettings(int $storeId): array
    {
        $store = $this->getStore($storeId);
        if (!$store) {
            return [];
        }

        // Decode JSON fields
        $jsonFields = ['settings', 'social_links', 'business_hours', 'shipping_policies', 'return_policies', 'payment_methods', 'metadata'];
        foreach ($jsonFields as $field) {
            if (!empty($store[$field])) {
                $store[$field] = json_decode($store[$field], true) ?: [];
            }
        }

        return $store;
    }
}