<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Subscriber;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionSubscriberInterface;

abstract class ScheduleSubscriber implements CronDefinitionSubscriberInterface
{

    protected DatabaseService $database;

    public function __construct(DatabaseService $databaseService)
    {
        $this->database = $databaseService;
    }

}