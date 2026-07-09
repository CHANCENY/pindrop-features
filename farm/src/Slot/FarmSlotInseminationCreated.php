<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Insemination recorded.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotInseminationCreated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('insemination_id', $payload)) {
            $context['insemination_id'] = $payload['insemination_id'];
        }
        if (array_key_exists('sow_id', $payload)) {
            $context['sow_id'] = $payload['sow_id'];
        }
        if (array_key_exists('boar_id', $payload)) {
            $context['boar_id'] = $payload['boar_id'];
        }

        error_log('[farm] ' . 'Insemination recorded' . ': ' . json_encode($context));
    }
}
