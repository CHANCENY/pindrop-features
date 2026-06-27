<?php

namespace Simp\Pindrop\Modules\core_signals\src\Plugin\Events;

use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Entity\User\UserVerification;
use Simp\Pindrop\Events\EventEmitter;
use Simp\Pindrop\Events\EventsSubscriberInterface;
use Simp\Pindrop\Events\SystemEvents\Events as SystemEvents;
use Simp\Pindrop\Modules\signals_slots\src\Service\SignalBus;

/**
 * Bridges Pindrop framework system events into the signals_slots bus.
 *
 * This is an explicit, deliberate bridge — core_signals is the plugin
 * that decides system events become signals. The signals_slots framework
 * itself has no knowledge of system events.
 *
 * Every emit() call injects '_signal' into the payload so that generic
 * slots (LogInfoSlot, NotifyAdminSlot, etc.) can identify which signal
 * fired them without needing to know the wiring upfront.
 *
 * Payloads are normalised to be JSON-safe (no PHP objects passed through).
 */
class EventsSubscriber implements EventsSubscriberInterface
{
    public function getSubscribedEvents(): array
    {
        return [
                // Auth
            SystemEvents::AUTH_LOGIN => [$this, 'onLogin'],
            SystemEvents::AUTH_LOGOUT => [$this, 'onLogout'],
            SystemEvents::AUTH_LOGIN_FAILED => [$this, 'onLoginFailed'],
            SystemEvents::AUTH_PASSWORD_RESET => [$this, 'onPasswordReset'],

                // User lifecycle
            SystemEvents::USER_CREATED => [$this, 'onUserCreated'],
            SystemEvents::USER_UPDATED => [$this, 'onUserUpdated'],
            SystemEvents::USER_DELETED => [$this, 'onUserDeleted'],

                // Entity lifecycle
            SystemEvents::ENTITY_CREATING => [$this, 'onEntityCreating'],
            SystemEvents::ENTITY_CREATED => [$this, 'onEntityCreated'],
            SystemEvents::ENTITY_UPDATING => [$this, 'onEntityUpdating'],
            SystemEvents::ENTITY_UPDATED => [$this, 'onEntityUpdated'],
            SystemEvents::ENTITY_DELETED => [$this, 'onEntityDeleted'],
            SystemEvents::ENTITY_SAVED => [$this, 'onEntitySaved'],

                // Plugin lifecycle
            SystemEvents::PLUGIN_INSTALLED => [$this, 'onPluginInstalled'],
            SystemEvents::PLUGIN_UNINSTALLED => [$this, 'onPluginUninstalled'],

                // Request
            SystemEvents::REQUEST_RECEIVED => [$this, 'onRequestReceived'],
        ];
    }

    // ----------------------------------------------------------------
    // Auth
    // ----------------------------------------------------------------

    /** Framework payload: ['session_id' => int] */
    public function onLogin(EventEmitter $event): void
    {
        $session_id = $event->session_id;
        $user = null;
        if (is_numeric($session_id)) {
            $user = CurrentUser::findById(getAppContainer()->get('database'), getAppContainer()->get('logger'), $session_id);
        } elseif (is_string($session_id)) {
            $user = CurrentUser::findBySessionId(getAppContainer()->get('database'), getAppContainer()->get('logger'), $session_id);
        }

        $payload = [];
        if ($user instanceof CurrentUser) {
            $currentUser = $user;
            $user = $user->getUser();

           $payload['to'] = $user->getEmail();
           $payload['subject'] = "New Login Alert ";
           $payload['body']    = "Hello,<p>Your account was successfully logged in on {$currentUser->getCreatedAt()->format('d F, Y h:i:s A')}.
           </p><p>If this was you, no action is required. If you do not recognize this activity, please contact support immediately.</p>
           <p>Regards,<br>Support Team</p>";
        }

        $this->emit('core.user.login', [
            'session_id' => $event->session_id ?? null,
            ...$payload
        ]);
    }

    /** Framework payload: ['user_id' => int] */
    public function onLogout(EventEmitter $event): void
    {
        
        $user = User::loadById($event->user_id, getAppContainer()->get('database'));
        $payload = [];
        if ($user instanceof User) {
            $currentUser = $user;
           $payload['to'] = $user->getEmail();
           $payload['subject'] = "Logout Confirmation";
           $payload['body']    = "Hello,<p>Your account was successfully logged out on {$currentUser->getCreatedAt()->format('d F, Y h:i:s A')}.
           </p><p>If this was you, no action is required. If you do not recognize this activity, please contact support immediately.</p>
           <p>Regards,<br>Support Team</p>";
        }
        $this->emit('core.user.logout', [
            'user_id' => $event->user_id ?? null,
            ...$payload
        ]);
    }

    /** Framework payload: [] */
    public function onLoginFailed(EventEmitter $event): void
    {
       
        $email = $event->email;
        $payload = [];
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
             $payload['to'] = $email;
             $date = date('d F, Y h:i:s A', time());
           $payload['subject'] = "Failed Login Attempt Detected";
           $payload['body']    = "Hello,<p>A failed login attempt was detected for your account on {$date}.
           </p><p>If this was you, no action is required. If you did not attempt to log in, we recommend reviewing your account security and changing your password if necessary.</p>
           <p>Regards,<br>Support Team</p>";
        }
        $this->emit('core.user.login_failed', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'email' => $event->email,
            ...$payload
        ]);
    }

    /** Framework payload: varies */
    public function onPasswordReset(EventEmitter $event): void
    {
        $this->emit('core.user.password_reset', $this->scalarise($event->raw));
    }

    // ----------------------------------------------------------------
    // User lifecycle
    // ----------------------------------------------------------------

    /** Framework payload: ['user' => array] */
    public function onUserCreated(EventEmitter $event): void
    {
        $this->emit('core.user.registered', [
            'user' => $this->scalarise($event->user ?? []),
        ]);
    }

    /** Framework payload: ['user' => array] */
    public function onUserUpdated(EventEmitter $event): void
    {
        $this->emit('core.user.updated', [
            'user' => $this->scalarise($event->user ?? []),
        ]);
    }

    /** Framework payload: ['uid' => int] */
    public function onUserDeleted(EventEmitter $event): void
    {
        $this->emit('core.user.deleted', [
            'user_id' => $event->uid ?? null,
        ]);
    }

    // ----------------------------------------------------------------
    // Entity lifecycle
    // ----------------------------------------------------------------

    /** Framework payload: ['entity' => array (mutable reference)] */
    public function onEntityCreating(EventEmitter $event): void
    {
        $this->emit('core.entity.creating', [
            'entity' => $this->scalarise($event->entity ?? []),
        ]);
    }

    /** Framework payload: ['entity' => array] */
    public function onEntityCreated(EventEmitter $event): void
    {
        $this->emit('core.entity.created', [
            'entity' => $this->scalarise($event->entity ?? []),
        ]);
    }

    /** Framework payload: ['entity' => array (mutable reference)] */
    public function onEntityUpdating(EventEmitter $event): void
    {
        $this->emit('core.entity.updating', [
            'entity' => $this->scalarise($event->entity ?? []),
        ]);
    }

    /** Framework payload: ['entity' => array] */
    public function onEntityUpdated(EventEmitter $event): void
    {
        $this->emit('core.entity.updated', [
            'entity' => $this->scalarise($event->entity ?? []),
        ]);
    }

    /** Framework payload: ['entity_id' => int] */
    public function onEntityDeleted(EventEmitter $event): void
    {
        $this->emit('core.entity.deleted', [
            'entity_id' => $event->entity_id ?? null,
        ]);
    }

    /**
     * Framework payload: ['entity' => StorageEntity object].
     * Objects are not JSON-safe — extract scalar identity info only.
     */
    public function onEntitySaved(EventEmitter $event): void
    {
        $entity = $event->entity ?? null;
        $entityId = null;
        $entityType = null;

        if (is_object($entity)) {
            $entityType = get_class($entity);
            if (method_exists($entity, 'id')) {
                $entityId = $entity->id();
            } elseif (isset($entity->id)) {
                $entityId = $entity->id;
            }
        } elseif (is_array($entity)) {
            $entityId = $entity['id'] ?? null;
            $entityType = $entity['type'] ?? null;
        }

        $this->emit('core.entity.saved', [
            'entity_id' => $entityId,
            'entity_type' => $entityType,
        ]);
    }

    // ----------------------------------------------------------------
    // Plugin lifecycle
    // ----------------------------------------------------------------

    /** Framework payload: ['plugin_id' => string, 'container' => object] */
    public function onPluginInstalled(EventEmitter $event): void
    {
        $this->emit('core.plugin.installed', [
            'plugin_id' => $event->plugin_id ?? null,
        ]);
    }

    /** Framework payload: ['plugin_id' => string, 'container' => object] */
    public function onPluginUninstalled(EventEmitter $event): void
    {
        $this->emit('core.plugin.uninstalled', [
            'plugin_id' => $event->plugin_id ?? null,
        ]);
    }

    // ----------------------------------------------------------------
    // Request
    // ----------------------------------------------------------------

    /** Framework payload: ['request' => Request object] */
    public function onRequestReceived(EventEmitter $event): void
    {
        $request = $event->request ?? null;

        $this->emit('core.request.received', [
            'method' => $request ? $request->getMethod() : null,
            'path' => $request ? $request->getPathInfo() : null,
        ]);
    }

    // ----------------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------------

    /**
     * Emit a signal, injecting '_signal' into the payload so generic
     * slots can identify what fired them.
     */
    private function emit(string $signal, array $payload): void
    {
        try {
            /** @var SignalBus $bus */
            $bus = \getAppContainer()->get(SignalBus::class);
            $bus->emit($signal, array_merge($payload, ['_signal' => $signal]));
        } catch (\Throwable) {
            // signals_slots not installed — fail silently
        }
    }

    /**
     * Recursively strip non-scalar values from an array so the result
     * is safe for JSON encoding into the async signal_queue.
     * Objects become their class name; resources become null.
     */
    private function scalarise(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([$this, 'scalarise'], $value);
        }

        if (is_object($value)) {
            return get_class($value);
        }

        if (is_resource($value)) {
            return null;
        }

        return $value;
    }
}
