<?php

namespace Simp\Pindrop\Modules\core_signals\src\Slot;

use Simp\Pindrop\Modules\audit_log\src\Service\AuditLogService;
use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Writes an audit log entry via the audit_log plugin.
 * Does nothing gracefully if audit_log is not installed.
 *
 * The signal's payload may include:
 *   'action'    string — overrides the audit action; falls back to _signal key
 *   'severity'  string — "info" | "warning" | "critical" (default: "info")
 *   'user_id'   int
 *   'username'  string
 */
class LogToAuditSlot implements SlotInterface
{
    public function handle(array $payload): void
    {
        $container = \getAppContainer();

        if (!$container->has(AuditLogService::class)) {
            return;
        }

        try {
            /** @var AuditLogService $auditLog */
            $auditLog = $container->get(AuditLogService::class);

            $auditLog->log(
                action:       $payload['action']   ?? $payload['_signal'] ?? 'signal',
                severity:     $payload['severity'] ?? 'info',
                userId:       isset($payload['user_id']) ? (int) $payload['user_id'] : null,
                username:     $payload['username'] ?? null,
                resourceType: $payload['resource_type'] ?? null,
                resourceId:   isset($payload['resource_id']) ? (string) $payload['resource_id'] : null,
                payload:      array_diff_key($payload, array_flip(['_signal', 'action', 'severity', 'user_id', 'username', 'resource_type', 'resource_id'])),
            );
        } catch (\Throwable) {
            // audit_log unavailable — fail silently
        }
    }
}
