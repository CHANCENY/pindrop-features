<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Purchase order created.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotPurchaseOrderCreated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('purchase_order_id', $payload)) {
            $context['purchase_order_id'] = $payload['purchase_order_id'];
        }

        error_log('[farm] ' . 'Purchase order created' . ': ' . json_encode($context));
    }
}
