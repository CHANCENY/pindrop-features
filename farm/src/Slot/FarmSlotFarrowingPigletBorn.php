<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Piglet registered.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotFarrowingPigletBorn implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('pig_id', $payload)) {
            $context['pig_id'] = $payload['pig_id'];
        }
        if (array_key_exists('farrowing_id', $payload)) {
            $context['farrowing_id'] = $payload['farrowing_id'];
        }

        error_log('[farm] ' . 'Piglet registered' . ': ' . json_encode($context));
    }
}
