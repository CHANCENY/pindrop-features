<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Barn created.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotBarnCreated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('barn_id', $payload)) {
            $context['barn_id'] = $payload['barn_id'];
        }
        if (array_key_exists('facility_id', $payload)) {
            $context['facility_id'] = $payload['facility_id'];
        }

        error_log('[farm] ' . 'Barn created' . ': ' . json_encode($context));
    }
}
