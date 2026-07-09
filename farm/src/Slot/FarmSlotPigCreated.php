<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Pig registered.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotPigCreated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('pig_id', $payload)) {
            $context['pig_id'] = $payload['pig_id'];
        }
        if (array_key_exists('facility_id', $payload)) {
            $context['facility_id'] = $payload['facility_id'];
        }
        if (array_key_exists('pen_id', $payload)) {
            $context['pen_id'] = $payload['pen_id'];
        }

        error_log('[farm] ' . 'Pig registered' . ': ' . json_encode($context));
    }
}
