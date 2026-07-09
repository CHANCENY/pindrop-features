<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Transaction recorded.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotTransactionRecorded implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('transaction_type', $payload)) {
            $context['transaction_type'] = $payload['transaction_type'];
        }

        error_log('[farm] ' . 'Transaction recorded' . ': ' . json_encode($context));
    }
}
