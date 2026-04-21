<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\Schedule;

interface CronDefinitionSubscriberInterface
{

    public function __construct(DatabaseService $databaseService);

    public function name(): string;

    public function id(): string;

    /**
     * @param array<Schedule> $schedules
     * @return string
     */
    public function runSchedules(array $schedules): string;
}