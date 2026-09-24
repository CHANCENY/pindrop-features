<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Services;

/**
 * Emits mailbox events onto the signals_slots bus, if that plugin is
 * installed. If it's not, getAppContainer()->get(...) throws (service not
 * registered) and we simply do nothing — mailbox works standalone, signals
 * integration is a bonus when the other plugin is present, matching the
 * pattern core_signals itself uses.
 */
final class MailboxSignals
{
    public static function messageReceived(string $folder, array $summary): void
    {
        self::emit('mailbox.message.received', ['folder' => $folder] + $summary);
    }

    public static function syncFailed(string $reason): void
    {
        self::emit('mailbox.sync.failed', ['reason' => $reason, 'at' => date(DATE_ATOM)]);
    }

    private static function emit(string $signal, array $payload): void
    {
        try {
            $bus = getAppContainer()->get('signals_slots.bus');
            $bus->emit($signal, $payload);
        } catch (\Throwable) {
            // signals_slots not installed, or emitting failed - never let this break mail sync.
        }
    }
}
