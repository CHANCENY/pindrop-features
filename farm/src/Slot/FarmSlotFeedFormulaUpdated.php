<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Feed formula updated.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotFeedFormulaUpdated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('formula_id', $payload)) {
            $context['formula_id'] = $payload['formula_id'];
        }

        error_log('[farm] ' . 'Feed formula updated' . ': ' . json_encode($context));
    }
}
