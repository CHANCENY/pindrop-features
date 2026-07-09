<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Feed silo created.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotFeedSiloCreated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('silo', $payload)) {
            $context['silo'] = $payload['silo'];
        }

        error_log('[farm] ' . 'Feed silo created' . ': ' . json_encode($context));
    }
}
