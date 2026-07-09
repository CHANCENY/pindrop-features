<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Treatment recorded.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotHealthTreatmentCreated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('pig_ids', $payload)) {
            $context['pig_ids'] = $payload['pig_ids'];
        }
        if (array_key_exists('diagnosis', $payload)) {
            $context['diagnosis'] = $payload['diagnosis'];
        }

        error_log('[farm] ' . 'Treatment recorded' . ': ' . json_encode($context));
    }
}
