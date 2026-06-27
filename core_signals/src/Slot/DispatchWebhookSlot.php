<?php

namespace Simp\Pindrop\Modules\core_signals\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;
use Simp\Pindrop\Modules\webhooks\src\Service\WebhookService;

/**
 * Relays the signal payload to all registered webhook endpoints via the
 * webhooks plugin. Does nothing gracefully if webhooks is not installed.
 *
 * The dispatched event name is, in order of preference:
 *   1. $payload['event']   — explicit override in the payload
 *   2. $payload['_signal'] — the signal key injected by SignalBus
 *   3. 'signal.dispatched' — fallback
 */
class DispatchWebhookSlot implements SlotInterface
{
    public function handle(array $payload): void
    {
        $container = \getAppContainer();

        if (!$container->has(WebhookService::class)) {
            return;
        }

        $event = $payload['event'] ?? $payload['_signal'] ?? 'signal.dispatched';

        // Strip internal meta key before forwarding
        $forwardPayload = array_diff_key($payload, ['_signal' => true, 'event' => true]);

        try {
            /** @var WebhookService $webhooks */
            $webhooks = $container->get(WebhookService::class);
            $webhooks->dispatch($event, $forwardPayload);
        } catch (\Throwable $e) {
            $container->get('logger')->error(
                'core.slot.dispatch_webhook failed: ' . $e->getMessage(),
                ['event' => $event]
            );
        }
    }
}
