<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Order;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class OrderActivity
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
     * Add order activity
     */
    public function addActivity(int $orderId, array $data): int
    {
        $this->validateActivityData($data);

        // Set defaults
        $data['order_id'] = $orderId;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['customer_visible'] = $data['customer_visible'] ?? false;

        // Handle JSON fields
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }

        $sql = "INSERT INTO commerce_order_activity (
            order_id, activity_type, activity_description, old_value, new_value,
            user_id, customer_visible, ip_address, user_agent, metadata, created_at
        ) VALUES (
            :order_id, :activity_type, :activity_description, :old_value, :new_value,
            :user_id, :customer_visible, :ip_address, :user_agent, :metadata, :created_at
        )";

        $activityId = $this->db->table('commerce_order_activity')->insert($data);
        
        $this->logger->info('Activity added', ['activity_id' => $activityId, 'order_id' => $orderId, 'type' => $data['activity_type']]);
        
        return $activityId;
    }

    /**
     * Add status change activity
     */
    public function addStatusChange(int $orderId, string $oldStatus, string $newStatus, ?int $userId = null, string $notes = ''): int
    {
        $data = [
            'activity_type' => 'status_change',
            'activity_description' => "Order status changed from {$oldStatus} to {$newStatus}",
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            'user_id' => $userId,
            'customer_visible' => true,
            'notes' => $notes
        ];

        return $this->addActivity($orderId, $data);
    }

    /**
     * Add payment update activity
     */
    public function addPaymentUpdate(int $orderId, string $paymentStatus, ?int $userId = null, string $notes = ''): int
    {
        $data = [
            'activity_type' => 'payment_update',
            'activity_description' => "Payment status updated to {$paymentStatus}",
            'new_value' => $paymentStatus,
            'user_id' => $userId,
            'customer_visible' => true,
            'notes' => $notes
        ];

        return $this->addActivity($orderId, $data);
    }

    /**
     * Add shipping update activity
     */
    public function addShippingUpdate(int $orderId, string $description, ?int $userId = null, string $notes = ''): int
    {
        $data = [
            'activity_type' => 'shipping_update',
            'activity_description' => $description,
            'user_id' => $userId,
            'customer_visible' => true,
            'notes' => $notes
        ];

        return $this->addActivity($orderId, $data);
    }

    /**
     * Add note activity
     */
    public function addNote(int $orderId, string $note, bool $customerVisible = false, ?int $userId = null, array $metadata = []): int
    {
        $data = [
            'activity_type' => 'note_added',
            'activity_description' => $note,
            'user_id' => $userId,
            'customer_visible' => $customerVisible,
            'metadata' => $metadata,
            'notes' => $note
        ];

        return $this->addActivity($orderId, $data);
    }

    /**
     * Add customer update activity
     */
    public function addCustomerUpdate(int $orderId, string $description, ?int $userId = null, string $notes = ''): int
    {
        $data = [
            'activity_type' => 'customer_update',
            'activity_description' => $description,
            'user_id' => $userId,
            'customer_visible' => false,
            'notes' => $notes
        ];

        return $this->addActivity($orderId, $data);
    }

    /**
     * Add admin action activity
     */
    public function addAdminAction(int $orderId, string $action, ?int $userId = null, array $metadata = []): int
    {
        $data = [
            'activity_type' => 'admin_action',
            'activity_description' => $action,
            'user_id' => $userId,
            'customer_visible' => false,
            'metadata' => $metadata
        ];

        return $this->addActivity($orderId, $data);
    }

    /**
     * Add system event activity
     */
    public function addSystemEvent(int $orderId, string $event, array $metadata = []): int
    {
        $data = [
            'activity_type' => 'system_event',
            'activity_description' => $event,
            'user_id' => null,
            'customer_visible' => false,
            'metadata' => $metadata
        ];

        return $this->addActivity($orderId, $data);
    }

    /**
     * Get activities by order ID
     */
    public function getActivitiesByOrder(int $orderId, int $limit = 50): array
    {
        $activities = $this->db->table('commerce_order_activity')->where('order_id','=',$orderId)->latest()->limit($limit)->get();
        
        // Decode JSON fields
        foreach ($activities as &$activity) {
            if ($activity['metadata']) {
                $activity['metadata'] = json_decode($activity['metadata'], true);
            }
        }
        
        return $activities;
    }

    /**
     * Get customer visible activities
     */
    public function getCustomerVisibleActivities(int $orderId, int $limit = 50): array
    {
        $activities = $this->db->table('commerce_order_activity')
            ->where('order_id','=',$orderId)->where('customer_visible','=',1)->latest()->limit($limit)->get();
        
        // Decode JSON fields
        foreach ($activities as &$activity) {
            if ($activity['metadata']) {
                $activity['metadata'] = json_decode($activity['metadata'], true);
            }
        }
        
        return $activities;
    }

    /**
     * Get activities by type
     */
    public function getActivitiesByType(string $activityType, int $limit = 50): array
    {
        $activities = $this->db->table('commerce_order_activity')
            ->where('activity_type','=',$activityType)->latest()->limit($limit)->get();
        
        // Decode JSON fields
        foreach ($activities as &$activity) {
            if ($activity['metadata']) {
                $activity['metadata'] = json_decode($activity['metadata'], true);
            }
        }
        
        return $activities;
    }

    /**
     * Get activities by user
     */
    public function getActivitiesByUser(int $userId, int $limit = 50): array
    {
        $activities = $this->db->table('commerce_order_activity')
            ->where('user_id','=',$userId)->latest()->limit($limit)->get();
        
        // Decode JSON fields
        foreach ($activities as &$activity) {
            if ($activity['metadata']) {
                $activity['metadata'] = json_decode($activity['metadata'], true);
            }
        }
        
        return $activities;
    }

    /**
     * Get activity by ID
     */
    public function getActivity(int $activityId): ?array
    {
        $activity = $this->db->table('commerce_order_activity')->where('id','=',$activityId)->first();
        
        if ($activity && $activity['metadata']) {
            $activity['metadata'] = json_decode($activity['metadata'], true);
        }
        
        return $activity;
    }

    /**
     * Get activity statistics
     */
    public function getActivityStats(int $storeId, ?string $startDate = null, ?string $endDate = null): array
    {
        $whereClause = "WHERE oa.order_id IN (SELECT id FROM commerce_orders WHERE store_id = ?)";
        $params = [$storeId];

        if ($startDate) {
            $whereClause .= " AND oa.created_at >= ?";
            $params[] = $startDate;
        }

        if ($endDate) {
            $whereClause .= " AND oa.created_at <= ?";
            $params[] = $endDate;
        }

        $sql = "SELECT 
            COUNT(*) as total_activities,
            COUNT(CASE WHEN oa.customer_visible = 1 THEN 1 END) as customer_visible_activities,
            COUNT(DISTINCT oa.activity_type) as unique_activity_types,
            COUNT(DISTINCT oa.user_id) as unique_users,
            COUNT(CASE WHEN oa.activity_type = 'status_change' THEN 1 END) as status_changes,
            COUNT(CASE WHEN oa.activity_type = 'payment_update' THEN 1 END) as payment_updates,
            COUNT(CASE WHEN oa.activity_type = 'admin_action' THEN 1 END) as admin_actions
        FROM commerce_order_activity oa {$whereClause}";

        $qb = $this->db->table('commerce_order_activity')->where('store_id','=',$storeId);
        if ($startDate) $qb->where('created_at','>=',(string)$startDate);
        if ($endDate)   $qb->where('created_at','<=',(string)$endDate);
        return $qb->first();
    }

    /**
     * Get activity timeline for order
     */
    public function getActivityTimeline(int $orderId): array
    {
        $activities = $this->db->table('commerce_order_activity')
            ->leftJoin('users', 'commerce_order_activity.user_id', '=', 'users.id')
            ->where('commerce_order_activity.order_id', '=', $orderId)
            ->oldest('commerce_order_activity.created_at')
            ->get();
        
        // Decode JSON fields
        foreach ($activities as &$activity) {
            if ($activity['metadata']) {
                $activity['metadata'] = json_decode($activity['metadata'], true);
            }
        }
        
        return $activities;
    }

    /**
     * Get recent activities across all orders
     */
    public function getRecentActivities(int $storeId, int $limit = 20): array
    {
        $activities = $this->db->table('commerce_order_activity')
            ->join('commerce_orders', 'commerce_order_activity.order_id', '=', 'commerce_orders.id')
            ->leftJoin('commerce_customer', 'commerce_orders.customer_id', '=', 'commerce_customer.id')
            ->where('commerce_orders.store_id', '=', $storeId)
            ->latest('commerce_order_activity.created_at')
            ->limit($limit)
            ->get();
        
        // Decode JSON fields
        foreach ($activities as &$activity) {
            if ($activity['metadata']) {
                $activity['metadata'] = json_decode($activity['metadata'], true);
            }
        }
        
        return $activities;
    }

    /**
     * Search activities
     */
    public function searchActivities(string $query, ?int $storeId = null, int $limit = 20): array
    {
        $sql = "SELECT 
            oa.*,
            o.order_number
        FROM commerce_order_activity oa
        JOIN commerce_orders o ON oa.order_id = o.id
        WHERE oa.activity_description LIKE ?";
        $params = ["%{$query}%"];

        if ($storeId) {
            $sql .= " AND o.store_id = ?";
            $params[] = $storeId;
        }

        $sql .= " ORDER BY oa.created_at DESC LIMIT ?";
        $params[] = $limit;

        $qb = $this->db->table('commerce_order_activity')->where('order_id','=',$orderId);
        if ($activityType) $qb->where('activity_type','=',$activityType);
        if ($startDate)    $qb->where('created_at','>=',(string)$startDate);
        if ($endDate)      $qb->where('created_at','<=',(string)$endDate);
        $activities = $qb->latest()->get();
        
        // Decode JSON fields
        foreach ($activities as &$activity) {
            if ($activity['metadata']) {
                $activity['metadata'] = json_decode($activity['metadata'], true);
            }
        }
        
        return $activities;
    }

    /**
     * Delete old activities (cleanup)
     */
    public function deleteOldActivities(int $daysOld = 365): int
    {
        $deletedCount = $this->db->table('commerce_order_activity')
            ->whereRaw('created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$daysOld])
            ->delete();
        
        $this->logger->info('Old activities deleted', ['days_old' => $daysOld, 'deleted_count' => $deletedCount]);
        
        return $deletedCount;
    }

    /**
     * Validate activity data
     */
    protected function validateActivityData(array $data): void
    {
        $required = ['order_id', 'activity_type', 'activity_description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (isset($data['activity_type'])) {
            $validTypes = ['status_change', 'payment_update', 'shipping_update', 'note_added', 'customer_update', 'admin_action', 'system_event'];
            if (!in_array($data['activity_type'], $validTypes)) {
                throw new \InvalidArgumentException("Invalid activity type: {$data['activity_type']}");
            }
        }

        if (isset($data['customer_visible']) && !is_bool($data['customer_visible'])) {
            throw new \InvalidArgumentException("Customer visible must be a boolean");
        }
    }
}
