<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

/**
 * QaSignalDispatcher
 *
 * `qa` does NOT declare a hard `dependencies: [signals_slots]` in info.yml —
 * it works standalone. If the `signals_slots` plugin happens to be installed
 * (see signals.yml for the qa.* signals this plugin declares), events emitted
 * here become available for other modules (reputation UI, notifications,
 * AI moderation, etc.) to react to without this plugin needing to know they
 * exist. This mirrors the defensive try/catch pattern used by `core_signals`.
 */
class QaSignalDispatcher
{
    public function emit(string $signal, array $payload = []): void
    {
        try {
            $signalBusClass = 'Simp\\Pindrop\\Modules\\signals_slots\\src\\Service\\SignalBus';
            if (!class_exists($signalBusClass)) {
                return; // signals_slots not installed — no-op.
            }

            $bus = \getAppContainer()->get($signalBusClass);
            $bus->emit($signal, $payload);
        } catch (\Throwable) {
            // Never let an optional integration break a core Q&A action.
        }
    }
}
