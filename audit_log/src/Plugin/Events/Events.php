<?php

namespace Simp\Pindrop\Modules\audit_log\src\Plugin\Events;

/**
 * Event constants specific to the audit_log plugin.
 *
 * Other plugins can fire AUDIT_LOG_ENTRY to write a custom audit entry
 * without taking a direct dependency on AuditLogService:
 *
 *   appEvents()->invokeEvents(Events::AUDIT_LOG_ENTRY, [
 *       'action'        => 'order.placed',
 *       'severity'      => 'info',
 *       'user_id'       => $userId,
 *       'username'      => $username,
 *       'resource_type' => 'order',
 *       'resource_id'   => (string) $orderId,
 *       'payload'       => ['total' => $total],
 *   ]);
 */
class Events
{
    /**
     * Fired when any plugin wants to write a custom audit entry.
     * Payload keys mirror AuditLogService::log() parameters.
     */
    public const string AUDIT_LOG_ENTRY = 'audit_log.entry';
}
