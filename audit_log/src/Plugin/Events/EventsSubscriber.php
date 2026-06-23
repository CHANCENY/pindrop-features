<?php

namespace Simp\Pindrop\Modules\audit_log\src\Plugin\Events;

use Simp\Pindrop\Events\EventEmitter;
use Simp\Pindrop\Events\EventsSubscriberInterface;
use Simp\Pindrop\Events\SystemEvents\Events as SystemEvents;
use Simp\Pindrop\Modules\audit_log\src\Service\AuditLogService;

/**
 * Listens to framework system events and writes audit entries for each.
 *
 * The subscriber is instantiated by PluginManager without constructor
 * arguments, so all dependencies are resolved lazily via getAppContainer().
 */
class EventsSubscriber implements EventsSubscriberInterface
{
    public function getSubscribedEvents(): array
    {
        return [
            // Auth events
            SystemEvents::AUTH_LOGIN        => [$this, 'onLogin'],
            SystemEvents::AUTH_LOGOUT       => [$this, 'onLogout'],
            SystemEvents::AUTH_LOGIN_FAILED => [$this, 'onLoginFailed'],

            // User lifecycle
            SystemEvents::USER_CREATED => [$this, 'onUserCreated'],
            SystemEvents::USER_DELETED => [$this, 'onUserDeleted'],

            // Plugin lifecycle
            SystemEvents::PLUGIN_INSTALLED   => [$this, 'onPluginInstalled'],
            SystemEvents::PLUGIN_UNINSTALLED => [$this, 'onPluginUninstalled'],

            // Relay: other plugins can fire this event to write a custom entry
            Events::AUDIT_LOG_ENTRY => [$this, 'onCustomEntry'],
        ];
    }

    // ----------------------------------------------------------------
    // Auth handlers
    // ----------------------------------------------------------------

    /**
     * Payload: ['session_id' => int]
     */
    public function onLogin(EventEmitter $event): void
    {
        [$userId, $username] = $this->resolveCurrentUser();
       
        $this->service()->log(
            action:       'user.login',
            severity:     'info',
            userId:       $userId,
            username:     $username,
            resourceType: 'session',
            resourceId:   (string) ($event->session_id ?? ''),
        );
    }

    /**
     * Payload: ['user_id' => int]
     */
    public function onLogout(EventEmitter $event): void
    {
        $userId   = isset($event->user_id) ? (int) $event->user_id : null;
        $username = $this->usernameFor($userId);

        $this->service()->log(
            action:   'user.logout',
            severity: 'info',
            userId:   $userId,
            username: $username,
        );
    }

    /**
     * Payload: empty — we don't know who failed, just the IP.
     */
    public function onLoginFailed(EventEmitter $event): void
    {
        $this->service()->log(
            action:   'user.login_failed',
            severity: 'warning',
        );
    }

    // ----------------------------------------------------------------
    // User lifecycle handlers
    // ----------------------------------------------------------------

    /**
     * Payload: ['user_id' => int, 'email' => string, ...]  (framework-dependent)
     */
    public function onUserCreated(EventEmitter $event): void
    {
        $userId   = isset($event->user_id) ? (int) $event->user_id : null;
        $username = $payload['email'] ?? $payload['username'] ?? null;

        $this->service()->log(
            action:       'user.created',
            severity:     'info',
            userId:       $userId,
            username:     $username,
            resourceType: 'user',
            resourceId:   (string) ($userId ?? ''),
        );
    }

    /**
     * Payload: ['user_id' => int]
     */
    public function onUserDeleted(EventEmitter $event): void
    {
        $userId = isset($event->user_id) ? (int) $event->user_id: null;

        $this->service()->log(
            action:       'user.deleted',
            severity:     'warning',
            userId:       $userId,
            resourceType: 'user',
            resourceId:   (string) ($userId ?? ''),
        );
    }

    // ----------------------------------------------------------------
    // Plugin lifecycle handlers
    // ----------------------------------------------------------------

    /**
     * Payload: ['plugin_id' => string, 'container' => ...]
     */
    public function onPluginInstalled(EventEmitter $event): void
    {
        [$userId, $username] = $this->resolveCurrentUser();

        $this->service()->log(
            action:       'plugin.installed',
            severity:     'info',
            userId:       $userId,
            username:     $username,
            resourceType: 'plugin',
            resourceId:   $event->plugin_id ?? null,
        );
    }

    /**
     * Payload: ['plugin_id' => string]
     */
    public function onPluginUninstalled(EventEmitter $event): void
    {
       
        [$userId, $username] = $this->resolveCurrentUser();
      

        $this->service()->log(
            action:       'plugin.uninstalled',
            severity:     'warning',
            userId:       $userId,
            username:     $username,
            resourceType: 'plugin',
            resourceId:   $event->plugin_id ?? null,
        );
    }

    // ----------------------------------------------------------------
    // Custom relay handler
    // ----------------------------------------------------------------

    /**
     * Relay: any plugin fires Events::AUDIT_LOG_ENTRY with the same
     * keys as AuditLogService::log() to write a custom entry.
     */
    public function onCustomEntry(EventEmitter $event): void
    {
        $this->service()->log(
            action:       $payload['action']        ?? 'custom',
            severity:     $payload['severity']      ?? 'info',
            userId:       isset($event->user_id) ? (int) $event->user_id : null,
            username:     $payload['username']      ?? null,
            resourceType: $payload['resource_type'] ?? null,
            resourceId:   isset($event->resource_id)
                              ? (string) $event->resource_id
                              : null,
            payload:      $payload['payload']       ?? [],
        );
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    private function service(): AuditLogService
    {
        return \getAppContainer()->get(AuditLogService::class);
    }

    /**
     * Try to resolve the current logged-in user from the container.
     * Returns [userId|null, username|null].
     */
    private function resolveCurrentUser(): array
    {
        try {
            $currentUser = \getAppContainer()->get('current_user');
            if ($currentUser && method_exists($currentUser, 'id') && $currentUser->id()) {
                $user     = $currentUser->getUser();
                $userId   = (int) $currentUser->id();
                $username = method_exists($user, 'getEmail')
                    ? $user->getEmail()
                    : ($user->email ?? null);
                return [$userId, $username];
            }
        } catch (\Throwable) {
            // Not in a request context or user not resolved yet — that's fine.
        }

        return [null, null];
    }

    /**
     * Look up a display name for a known user ID without a DB call —
     * only uses the already-resolved current_user session.
     */
    private function usernameFor(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        try {
            $currentUser = \getAppContainer()->get('current_user');
            if ($currentUser && (int) $currentUser->id() === $userId) {
                $user = $currentUser->getUser();
                return method_exists($user, 'getEmail')
                    ? $user->getEmail()
                    : ($user->email ?? null);
            }
        } catch (\Throwable) {}

        return null;
    }
}
