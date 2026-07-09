<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Pen load updated.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotPenUpdated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('pen_id', $payload)) {
            $context['pen_id'] = $payload['pen_id'];
        }
        if (array_key_exists('current_load', $payload)) {
            $context['current_load'] = $payload['current_load'];
        }

        error_log('[farm] ' . 'Pen load updated' . ': ' . json_encode($context));
    }
}
