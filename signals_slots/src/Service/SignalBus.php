<?php

namespace Simp\Pindrop\Modules\signals_slots\src\Service;

use PDO;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;
use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * The one thing other plugins call:
 *
 *   $bus = getAppContainer()->get(SignalBus::class);
 *   $bus->emit('my_plugin.user_registered', ['user_id' => 42, 'email' => '...']);
 *
 * SignalBus looks up every active connection for that signal and either:
 *   - sync  → calls the slot's handle() immediately, logs the result
 *   - async → pushes a row to signal_queue for cron to drain
 */
class SignalBus
{
    protected PDO $pdo;

    public function __construct(
        protected DatabaseService  $databaseService,
        protected SignalRegistry   $signalRegistry,
        protected LoggerInterface  $logger
    ) {
        $this->pdo = $this->databaseService->getPdo();
    }

    /**
     * Emit a signal.
     *
     * @param string $signal   The signal key as declared in signals.yml.
     * @param array  $payload  Arbitrary data — whatever makes sense for this signal.
     */
    public function emit(string $signal, array $payload = []): void
    {
        // Unknown signals are silently ignored — emitting without a declaration
        // is allowed (useful during development), but no connections will fire.
        $connections = $this->activeConnectionsFor($signal);

        foreach ($connections as $connection) {
            if ($connection['mode'] === 'sync') {
                $this->invokeSync($signal, $connection['slot_id'], $payload);
            } else {
                $this->enqueueAsync($signal, $connection['slot_id'], $payload);
            }
        }
    }

    // ----------------------------------------------------------------
    // Internal — sync path
    // ----------------------------------------------------------------

    private function invokeSync(string $signal, string $slotId, array $payload): void
    {
        $slotDef = $this->signalRegistry->getSlot($slotId);

        if (!$slotDef) {
            $this->logDelivery($signal, $slotId, 'sync', $payload, false, "Slot '{$slotId}' not found in registry.");
            return;
        }

        try {
            /** @var SlotInterface $slot */
            $slot = new $slotDef['class']();
            $slot->handle($payload);
            $this->logDelivery($signal, $slotId, 'sync', $payload, true);
        } catch (\Throwable $e) {
            $this->logger->error('signals_slots: sync slot failed', [
                'signal'  => $signal,
                'slot_id' => $slotId,
                'error'   => $e->getMessage(),
            ]);
            $this->logDelivery($signal, $slotId, 'sync', $payload, false, $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // Internal — async path
    // ----------------------------------------------------------------

    private function enqueueAsync(string $signal, string $slotId, array $payload): void
    {
        $this->databaseService->table('signal_queue')->insert([
            'signal_key' => $signal,
            'slot_id'    => $slotId,
            'payload'    => json_encode($payload),
            'status'     => 'pending',
        ]);
    }

    // ----------------------------------------------------------------
    // Called by SignalQueueSubscriber (cron drain)
    // ----------------------------------------------------------------

    /**
     * Drain all pending rows from signal_queue.
     * Returns a summary string for the cron log.
     */
    public function drainQueue(): string
    {
        $stmt = $this->pdo->query("
            SELECT * FROM signal_queue
            WHERE  status = 'pending'
            ORDER  BY created_at ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return 'Signal queue empty — nothing to process.';
        }

        $ok   = 0;
        $fail = 0;

        foreach ($rows as $row) {
            // Mark as processing to avoid double-processing in concurrent runs
            $this->pdo->prepare("UPDATE signal_queue SET status = 'processing' WHERE id = :id")
                ->execute([':id' => $row['id']]);

            $payload = $row['payload'] ? json_decode($row['payload'], true) : [];
            $slotDef = $this->signalRegistry->getSlot($row['slot_id']);

            if (!$slotDef) {
                $this->markQueueRow($row['id'], 'failed');
                $this->logDelivery($row['signal_key'], $row['slot_id'], 'async', $payload, false, "Slot not found in registry.");
                $fail++;
                continue;
            }

            try {
                /** @var SlotInterface $slot */
                $slot = new $slotDef['class']();
                $slot->handle($payload);
                $this->markQueueRow($row['id'], 'done');
                $this->logDelivery($row['signal_key'], $row['slot_id'], 'async', $payload, true);
                $ok++;
            } catch (\Throwable $e) {
                $this->logger->error('signals_slots: async slot failed', [
                    'signal'    => $row['signal_key'],
                    'slot_id'   => $row['slot_id'],
                    'queue_id'  => $row['id'],
                    'error'     => $e->getMessage(),
                ]);
                $this->markQueueRow($row['id'], 'failed');
                $this->logDelivery($row['signal_key'], $row['slot_id'], 'async', $payload, false, $e->getMessage());
                $fail++;
            }
        }

        return "Signal queue drained — {$ok} ok, {$fail} failed.";
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function activeConnectionsFor(string $signal): array
    {
        return $this->databaseService->table('signal_connections')
            ->where('signal_key', '=', $signal)
            ->where('active', '=', 1)
            ->get();
    }

    private function markQueueRow(int $id, string $status): void
    {
        $this->pdo->prepare("
            UPDATE signal_queue
            SET    status = :status, processed_at = NOW()
            WHERE  id = :id
        ")->execute([':status' => $status, ':id' => $id]);
    }

    private function logDelivery(
        string  $signal,
        string  $slotId,
        string  $mode,
        array   $payload,
        bool    $success,
        ?string $error = null
    ): void {
        $this->databaseService->table('signal_delivery_log')->insert([
            'signal_key' => $signal,
            'slot_id'    => $slotId,
            'mode'       => $mode,
            'payload'    => json_encode($payload),
            'success'    => $success ? 1 : 0,
            'error'      => $error,
        ]);
    }
}
