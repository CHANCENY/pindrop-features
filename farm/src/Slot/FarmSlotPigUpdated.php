<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Pig updated.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotPigUpdated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('pig_id', $payload)) {
            $context['pig_id'] = $payload['pig_id'];
        }

        error_log('[farm] ' . 'Pig updated' . ': ' . json_encode($context));
    }
}
