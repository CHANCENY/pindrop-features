<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Cron;

use Cron\CronExpression;
use DI\DependencyException;
use DI\NotFoundException;

class Schedule
{
    protected CronManager $cronManager;
    protected CronExpression $cronExpression;
    public int $interval;

    public function __construct(
        public $id,
        public   $job_name,
        public $expression,
        public $status,
        public   $last_run,
        public         $next_run,
        public  $duration_seconds,
        public          $created_at,
        public           $updated_at,
        public  $job,
        public $subscriber
    )
    {
        $this->cronManager = \getAppContainer()->get('cron.manager');
        $this->cronExpression = new CronExpression($this->expression);
        $this->interval = time();
    }

    /**
     * @throws \DateInvalidTimeZoneException|\DateMalformedStringException
     */
    public function isDue(): bool
    {
        $job = $this->cronManager->getJob($this->job);
        $timezone = $job['timezone'] ?? 'Africa/Blantyre';

        $tz = new \DateTimeZone($timezone);

        $now = new \DateTime('now', $tz);
        $nextRun = new \DateTime($this->next_run, $tz);

        // Job is due if current time is equal or past next run
        return $now >= $nextRun;
    }
    /**
     * @throws \Exception
     */
    public function finished(): void
    {
        $nowTime = time();
        $job = $this->cronManager->getJob($this->job);
        $timezone = $job['timezone'] ?? 'Africa/Blantyre';
        $data['last_run'] = new \DateTime('now', new \DateTimeZone($timezone))->format('Y-m-d H:i:s');
        $data['next_run'] = $this->cronExpression->getNextRunDate(timeZone: $timezone)->format('Y-m-d H:i:s');
        $data['duration_seconds'] = ($nowTime - $this->interval) + ($this->duration_seconds  > 0 ? $this->duration_seconds : 1);
        $data['id'] = $this->id;

        $sql = "UPDATE scheduler_jobs SET last_run = :last_run, next_run = :next_run, duration_seconds = :duration_seconds WHERE id = :id";
        \getAppContainer()->get('database')->query($sql, ...$data);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function addLog(string $message, string $type): void
    {
        $types = ['start', 'debug', 'warn', 'error', 'ok', 'info'];
        if (in_array($type, $types)) {
            $log =  [
                'message' => $message,
                'message_type' => $type,
                'schedule_id' => $this->id,
                'job_name' => $this->job_name,
            ];

            $query = "INSERT INTO scheduler_logs (message, message_type, schedule_id, job_name) VALUES (:message, :message_type, :schedule_id, :job_name)";
            \getAppContainer()->get('database')->query($query, ...$log);
        }

    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getLogs(?string $type = null): array
    {
        $query = "SELECT * FROM scheduler_logs WHERE schedule_id = :id";
        if ($type) {
            $query .= " AND message_type = :message_type";
            $data['message_type'] = $type;
        }
        $data['id'] = $this->id;
        $results = \getAppContainer()->get('database')->query($query, ...$data)->fetchAll();
        return array_map(function ($item) {
            return new Log(...$item);
        }, $results);
    }
}