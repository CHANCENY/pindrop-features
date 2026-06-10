<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Cron;

use Cron\CronExpression;

class Schedule
{
    protected CronManager    $cronManager;
    protected CronExpression $cronExpression;
    public int $interval;

    public function __construct(
        public $id,
        public $job_name,
        public $expression,
        public $status,
        public $last_run,
        public $next_run,
        public $duration_seconds,
        public $created_at,
        public $updated_at,
        public $job,
        public $subscriber
    ) {
        $this->cronManager    = \getAppContainer()->get('cron.manager');
        $this->cronExpression = new CronExpression($this->expression);
        $this->interval       = time();
    }

    public function isDue(): bool
    {
        $job      = $this->cronManager->getJob($this->job);
        $timezone = $job['timezone'] ?? 'Africa/Blantyre';
        $tz       = new \DateTimeZone($timezone);
        $now      = new \DateTime('now', $tz);
        $nextRun  = new \DateTime($this->next_run, $tz);
        return $now >= $nextRun;
    }

    public function finished(): void
    {
        $nowTime  = time();
        $job      = $this->cronManager->getJob($this->job);
        $timezone = $job['timezone'] ?? 'Africa/Blantyre';

        \getAppContainer()->get('database')
            ->table('scheduler_jobs')
            ->where('id', '=', $this->id)
            ->update([
                'last_run'         => (new \DateTime('now', new \DateTimeZone($timezone)))->format('Y-m-d H:i:s'),
                'next_run'         => $this->cronExpression->getNextRunDate(timeZone: $timezone)->format('Y-m-d H:i:s'),
                'duration_seconds' => ($nowTime - $this->interval) + ($this->duration_seconds > 0 ? $this->duration_seconds : 1),
            ]);
    }

    public function addLog(string $message, string $type): void
    {
        $allowed = ['start', 'debug', 'warn', 'error', 'ok', 'info'];
        if (!in_array($type, $allowed, true)) {
            return;
        }

        \getAppContainer()->get('database')
            ->table('scheduler_logs')
            ->insert([
                'message'      => $message,
                'message_type' => $type,
                'schedule_id'  => $this->id,
                'job_name'     => $this->job_name,
            ]);
    }

    public function getLogs(?string $type = null): array
    {
        $qb = \getAppContainer()->get('database')
            ->table('scheduler_logs')
            ->where('schedule_id', '=', $this->id);

        if ($type !== null) {
            $qb->where('message_type', '=', $type);
        }

        return array_map(
            fn($item) => new Log(...$item),
            $qb->get()
        );
    }
}
