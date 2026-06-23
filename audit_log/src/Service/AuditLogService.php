<?php

namespace Simp\Pindrop\Modules\audit_log\src\Service;

use PDO;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Settings\Settings;

class AuditLogService
{
    protected PDO $pdo;

    public function __construct(
        protected DatabaseService $databaseService,
        protected ?Settings        $settings = null
    ) {
        $this->pdo = $this->databaseService->getPdo();
        $this->settings = new Settings($this->databaseService);
    }

    // ----------------------------------------------------------------
    // Writing
    // ----------------------------------------------------------------

    /**
     * Record an audit entry.
     *
     * @param string      $action       Machine-readable action name  e.g. "user.login"
     * @param string      $severity     "info" | "warning" | "critical"
     * @param int|null    $userId       Actor's user ID (null for anonymous events)
     * @param string|null $username     Actor's display name / email
     * @param string|null $resourceType Type of the affected resource  e.g. "plugin"
     * @param string|null $resourceId   Identifier of the affected resource
     * @param array       $payload      Any extra data to store as JSON
     */
    public function log(
        string  $action,
        string  $severity     = 'info',
        ?int    $userId       = null,
        ?string $username     = null,
        ?string $resourceType = null,
        ?string $resourceId   = null,
        array   $payload      = []
    ): void {
        $this->databaseService
            ->table('audit_log_entries')
            ->insert([
                'user_id'       => $userId,
                'username'      => $username,
                'action'        => $action,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
                'ip_address'    => $_SERVER['REMOTE_ADDR']              ?? null,
                'user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                'payload'       => empty($payload) ? null : json_encode($payload),
                'severity'      => in_array($severity, ['info', 'warning', 'critical'])
                                     ? $severity
                                     : 'info',
            ]);
    }

    // ----------------------------------------------------------------
    // Reading
    // ----------------------------------------------------------------

    /**
     * Return a filtered, paginated page of entries, newest-first.
     *
     * Supported $filters keys:
     *   user_id   int
     *   action    string  (partial match)
     *   severity  string  exact match
     *   date_from string  Y-m-d
     *   date_to   string  Y-m-d
     */
    public function findAll(
        array $filters  = [],
        int   $limit    = 50,
        int   $offset   = 0
    ): array {
        [$sql, $params] = $this->buildWhereClause($filters);

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM   audit_log_entries
            {$sql}
            ORDER  BY created_at DESC
            LIMIT  :limit
            OFFSET :offset
        ");

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count entries matching the given filters (for pagination).
     */
    public function countAll(array $filters = []): int
    {
        [$sql, $params] = $this->buildWhereClause($filters);

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM audit_log_entries {$sql}
        ");

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete all entries older than $days days.
     * Returns the number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM audit_log_entries
            WHERE  created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Convenience: purge using the retention_days value from plugin settings.
     * Falls back to 90 days if the setting is not configured.
     */
    public function purgeByRetentionPolicy(): int
    {
        $setting = $this->settings->getSetting('audit_log.settings');
        $days    = (int) ($setting?->get('retention_days') ?? 90);
        $days    = max(1, $days);  // never less than 1 day

        return $this->purgeOlderThan($days);
    }

    // ----------------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------------

    /**
     * Build a parameterised WHERE clause string + bindings from a
     * $filters array.  Returns [$whereString, $params].
     */
    private function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $params     = [];

        if (!empty($filters['user_id'])) {
            $conditions[] = 'user_id = :f_user_id';
            $params[':f_user_id'] = (int) $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $conditions[] = 'action LIKE :f_action';
            $params[':f_action'] = '%' . $filters['action'] . '%';
        }

        if (!empty($filters['severity'])) {
            $conditions[] = 'severity = :f_severity';
            $params[':f_severity'] = $filters['severity'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'created_at >= :f_date_from';
            $params[':f_date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'created_at <= :f_date_to';
            $params[':f_date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $sql = empty($conditions)
            ? ''
            : 'WHERE ' . implode(' AND ', $conditions);

        return [$sql, $params];
    }
}
