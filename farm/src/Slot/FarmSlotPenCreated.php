<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Pen created.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotPenCreated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('pen_id', $payload)) {
            $context['pen_id'] = $payload['pen_id'];
        }
        if (array_key_exists('barn_id', $payload)) {
            $context['barn_id'] = $payload['barn_id'];
        }

        error_log('[farm] ' . 'Pen created' . ': ' . json_encode($context));
    }
}
