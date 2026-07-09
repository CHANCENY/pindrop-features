<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Facility updated.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotFacilityUpdated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('facility_id', $payload)) {
            $context['facility_id'] = $payload['facility_id'];
        }

        error_log('[farm] ' . 'Facility updated' . ': ' . json_encode($context));
    }
}
