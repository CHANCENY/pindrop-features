<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Cron;

use Exception;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionSubscriberInterface;

class SchedulerManager
{
    public function __construct(protected DatabaseService $databaseService)
    {
    }

    /**
     * Create a cron job definition.
     * @throws Exception
     */
    public function create(array $definition): bool|int
    {
        foreach (['name', 'category', 'timezone', 'environment', 'definition'] as $key) {
            if (!isset($definition[$key])) {
                throw new Exception("Mandatory parameter '$key' is missing");
            }
        }

        if (isset($definition['notify'])) {
            $definition['notify'] = (int) !empty($definition['notify']);
        }

        return $this->databaseService->table('schedulers')->insert($definition);
    }

    /**
     * Add a schedule for a cron job.
     * @throws Exception
     */
    public function addSchedule(array $definition): bool|int
    {
        foreach (['job_name', 'expression', 'status'] as $key) {
            if (!isset($definition[$key])) {
                throw new Exception("Mandatory parameter '$key' is missing");
            }
        }

        return $this->databaseService->table('scheduler_jobs')->insert($definition);
    }

    /**
     * Update a schedule by ID.
     */
    public function updateSchedule(array $definition, int $id): int
    {
        return $this->databaseService->table('scheduler_jobs')
            ->where('id', '=', $id)
            ->update($definition);
    }

    /**
     * Fetch a single schedule by ID.
     */
    public function getSchedule(int $id): ?Schedule
    {
        $row = $this->databaseService->table('scheduler_jobs')
            ->where('id', '=', $id)
            ->first();

        return $row ? new Schedule(...$row) : null;
    }

    /**
     * Delete a schedule by ID.
     */
    public function deleteSchedule(int $id): bool
    {
        return $this->databaseService->table('scheduler_jobs')
            ->where('id', '=', $id)
            ->delete() > 0;
    }

    /**
     * Fetch all schedules.
     * @return Schedule[]
     */
    public function getSchedules(): array
    {
        return array_map(
            fn($row) => new Schedule(...$row),
            $this->databaseService->table('scheduler_jobs')->get()
        );
    }

    /**
     * Fetch all running schedules for a given subscriber.
     * @return Schedule[]
     */
    public function getSchedulesBySubscriber(CronDefinitionSubscriberInterface|string $subscriber): array
    {
        $subscriberId = is_string($subscriber) ? $subscriber : $subscriber->id();

        return array_map(
            fn($row) => new Schedule(...$row),
            $this->databaseService->table('scheduler_jobs')
                ->where('status', '=', 'running')
                ->where('subscriber', '=', $subscriberId)
                ->get()
        );
    }
}
