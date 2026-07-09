<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Insemination updated.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotInseminationUpdated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('insemination_id', $payload)) {
            $context['insemination_id'] = $payload['insemination_id'];
        }

        error_log('[farm] ' . 'Insemination updated' . ': ' . json_encode($context));
    }
}
