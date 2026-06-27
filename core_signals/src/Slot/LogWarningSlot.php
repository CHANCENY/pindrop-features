<?php

namespace Simp\Pindrop\Modules\core_signals\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

class LogWarningSlot implements SlotInterface
{
    public function handle(array $payload): void
    {
        $signal = $payload['_signal'] ?? 'unknown';
        \getAppContainer()->get('logger')->warning("[signal] {$signal}", $payload);
    }
}
