<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Vaccination schedule updated.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotVaccinationUpdated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('vaccination_id', $payload)) {
            $context['vaccination_id'] = $payload['vaccination_id'];
        }
        if (array_key_exists('status', $payload)) {
            $context['status'] = $payload['status'];
        }

        error_log('[farm] ' . 'Vaccination schedule updated' . ': ' . json_encode($context));
    }
}
