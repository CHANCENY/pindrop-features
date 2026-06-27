<?php

namespace Simp\Pindrop\Modules\core_signals\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Appends one JSON line per invocation to var/logs/signal_activity.log.
 * Creates the log directory if it does not exist.
 */
class LogToFileSlot implements SlotInterface
{
    public function handle(array $payload): void
    {
        $logDir  = defined('APP_ROOT') ? APP_ROOT . '/var/logs' : dirname(__DIR__, 6) . '/var/logs';
        $logFile = $logDir . '/signal_activity.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $line = json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'signal'    => $payload['_signal'] ?? 'unknown',
            'payload'   => $payload,
        ]) . PHP_EOL;

        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
