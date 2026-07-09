<?php

namespace Simp\Pindrop\Modules\farm\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Inventory item updated.
 *
 * Default slot for its corresponding farm.* signal. Logs the event; rewire
 * to a different signal or replace with custom handling via the
 * Signals & Slots admin UI.
 */
class FarmSlotInventoryUpdated implements SlotInterface
{
    public function handle(array $payload): void
    {
        $context = [];
        if (array_key_exists('inventory_id', $payload)) {
            $context['inventory_id'] = $payload['inventory_id'];
        }

        error_log('[farm] ' . 'Inventory item updated' . ': ' . json_encode($context));
    }
}
