<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Cron;

use Exception;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionSubscriberInterface;

class SchedulerManager
{
    public function __construct(protected DatabaseService $databaseService)
    {
    }

    /**
     * @throws Exception
     */
    public function create(array $definition): bool|int
    {
        $mandatories = ['name', 'category', 'timezone', 'environment', 'definition'];

        foreach ($mandatories as $mandatory) {
            if (!isset($definition[$mandatory])) {
                throw new Exception("Mandatory parameter '$mandatory' is missing");
            }
        }

        if (isset($definition['notify'])) {
            $definition['notify'] = !empty($definition['notify']);
        }

        $placeholder = array_keys($definition);
        $placeholderNamed = array_map(function ($value) {
            return ":{$value}";
        }, $placeholder);

        $sql = "INSERT INTO schedulers (".implode(', ', $placeholder).") VALUES (".implode(', ', $placeholderNamed).")";

        $result = $this->databaseService->query($sql, ...$definition);

        return is_bool($result) ? false : $this->databaseService->lastInsertId();
    }

    /**
     * @throws Exception
     */
    public function addSchedule(array $definition): bool|int
    {
        $mandatories = ['job_name', 'expression', 'status'];
        foreach ($mandatories as $mandatory) {
            if (!isset($definition[$mandatory])) {
                throw new Exception("Mandatory parameter '$mandatory' is missing");
            }
        }

        $placeholder = array_keys($definition);
        $placeholderNamed = array_map(function ($value) {
            return ":{$value}";
        },$placeholder);

        $sql = "INSERT INTO scheduler_jobs (".implode(', ', $placeholder).") VALUES (".implode(', ', $placeholderNamed).")";

        $result = $this->databaseService->query($sql, ...$definition);
        return is_bool($result) ? false : $this->databaseService->lastInsertId();
    }

    /**
     * @throws DatabaseException
     */
    public function updateSchedule(array $definition, int $id): bool|int
    {
        $placeholderNamed = array_map(function ($value) {
            return "{$value} = :{$value}";
        }, array_keys($definition));

        $sql = "UPDATE scheduler_jobs SET ".implode(', ', $placeholderNamed)." WHERE id = :id";
        $definition['id'] = $id;
        return $this->databaseService->query($sql, ...$definition)?->rowCount();
    }

    public function getSchedule(int $id): ?Schedule
    {
        $sql = "SELECT * FROM scheduler_jobs WHERE id = :id";
        $definition['id'] = $id;
        $result = $this->databaseService->query($sql, ...$definition);
        $job = $result->fetch();
        if ($job) {
            return new Schedule(...$job);
        }
        return null;
    }

    /**
     * @throws DatabaseException
     */
    public function deleteSchedule(int $id): bool
    {
        $sql = "DELETE FROM scheduler_jobs WHERE id = :id";
        $definition['id'] = $id;
        return $this->databaseService->query($sql, ...$definition)?->rowCount();
    }

    public function getSchedules(): array
    {
        $dbConnection = $this->databaseService->getPdo();
        $sql = 'SELECT * FROM scheduler_jobs';
        $statement = $dbConnection->prepare($sql);
        $statement->execute();
        return array_map(function ($job) { return new Schedule(...$job); }, $statement->fetchAll());
    }

    /**
     * @param CronDefinitionSubscriberInterface $subscriber
     * @return array<Schedule>
     * @throws DatabaseException
     */
    public function getSchedulesBySubscriber(CronDefinitionSubscriberInterface|string $subscriber): array
    {
        $query = 'SELECT * FROM scheduler_jobs WHERE `status` = :status AND `subscriber` = :subscriber';
        $data['status'] = 'running';
        $data['subscriber'] = is_string($subscriber) ? $subscriber : $subscriber->id();
        $result = $this->databaseService->query($query, ...$data)->fetchAll();
        return array_map(function ($job) { return new Schedule(...$job); }, $result);
    }
}