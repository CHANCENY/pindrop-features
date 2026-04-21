<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Subscriber;

use Exception;

class DefaultScheduleSubscriber extends ScheduleSubscriber
{

    public function name(): string
    {
        return "System schedules subscriber";
    }

    public function id(): string
    {
        return "system.schedule.subscriber";
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function runSchedules(array $schedules): string
    {
        $messages = "";

        foreach ($schedules as $schedule) {
            $total = random_int(20, 150);
            $process_id = getmypid();
            $schedule->addLog("Schedule has started [{$process_id}]", 'start');
           for( $i = 0; $i < $total; $i++ ) {
               $schedule->addLog("Count [{$i}] of [{$total}]", 'info');
           }
           $schedule->finished();
        }

        return $messages;
    }
}