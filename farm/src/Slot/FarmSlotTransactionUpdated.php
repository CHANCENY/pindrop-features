<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Transaction updated.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotTransactionUpdated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('transaction_id', $payload)) {
            $context['transaction_id'] = $payload['transaction_id'];
        }

        error_log('[farm] ' . 'Transaction updated' . ': ' . json_encode($context));
    }
}
