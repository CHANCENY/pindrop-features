<?php

namespace Simp\Pindrop\Modules\signals_slots\src\Plugin\Cron;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionSubscriberInterface;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\Schedule;
use Simp\Pindrop\Modules\signals_slots\src\Service\SignalBus;

/**
 * Cron subscriber that drains the async signal_queue on every tick.
 *
 * Setup (one-time, via cron admin):
 *   1. Create a Scheduler job  (any name, environment = production, definition = any)
 *   2. Add a Schedule under it:
 *        expression  = * * * * *
 *        subscriber  = signals_slots.queue.subscriber
 *        status      = running
 *
 * After that, every minute cron will call runSchedules() which
 * delegates to SignalBus::drainQueue().
 */
class SignalQueueSubscriber implements CronDefinitionSubscriberInterface
{
    public function __construct(DatabaseService $databaseService)
    {
        // parent::__construct($databaseService);
    }

    public function name(): string
    {
        return 'Signal queue drain';
    }

    public function id(): string
    {
        return 'signals_slots.queue.subscriber';
    }

    /**
     * @param array<Schedule> $schedules
     */
    public function runSchedules(array $schedules): string
    {
        $messages = '';

        foreach ($schedules as $schedule) {
            $schedule->addLog('Signal queue drain started.', 'start');

            try {
                /** @var SignalBus $bus */
                $bus     = \getAppContainer()->get(SignalBus::class);
                $summary = $bus->drainQueue();

                $schedule->addLog($summary, 'ok');
                $messages .= $summary . PHP_EOL;
            } catch (\Throwable $e) {
                $schedule->addLog('Signal queue drain error: ' . $e->getMessage(), 'error');
                $messages .= 'Error: ' . $e->getMessage() . PHP_EOL;
            } finally {
                $schedule->finished();
            }
        }

        return $messages;
    }
}
