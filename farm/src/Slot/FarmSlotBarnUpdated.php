<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Barn updated.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotBarnUpdated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('barn_id', $payload)) {
            $context['barn_id'] = $payload['barn_id'];
        }

        error_log('[farm] ' . 'Barn updated' . ': ' . json_encode($context));
    }
}
