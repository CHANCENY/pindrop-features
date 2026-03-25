<?php

namespace Simp\Pindrop\Modules\streamer\src\Services;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;

class PlayerActivityRecorder
{
    public function __construct(protected DatabaseService $databaseService)
    {
    }

    /**
     * @throws DatabaseException
     */
    public function addRecord(array $record): bool
    {
        $columns = array_keys($record);
        $placeholders = array_map(function ($value) {
            return ":{$value}";
        },$columns);

        $query = "SELECT * FROM player_logs WHERE video_id = :video_id AND ip_address = :ip_address AND event = :event";
        $st = $this->databaseService->query($query, ...$i=[
            'video_id' => $record['video_id'],
            'ip_address' => $record['ip_address'],
            'event' => 'timeupdate'
        ]);
        $id = null;
        if ($st->rowCount() === 0) {
            $id = $st->fetch()['id'] ?? null;
            $query = "INSERT INTO player_logs (".implode(',', $columns).") VALUES (".implode(',', $placeholders).")";
            $this->databaseService->query($query, ...$record);
            return true;
        }

        $query = "UPDATE `player_logs` SET `current_time_played` = :current_time WHERE video_id = :video_id AND ip_address = :ip_address AND event = :event";
        $st = $this->databaseService->query($query, ...$i=[
            'current_time' => $record['current_time_played'],
            'video_id' => $record['video_id'],
            'ip_address' => $record['ip_address'],
            'event' => 'timeupdate'
        ]);

        return $st->rowCount() === 1;
    }

    public function getRecords(): array
    {
        return $this->databaseService->query("SELECT * FROM player_logs ORDER BY id DESC")->fetchAll();
    }

    public function getRecordById(int $id): ?array {
        return $this->databaseService->query("SELECT * FROM player_logs WHERE id = :id", $id)->fetch();
    }

    /**
     * @throws DatabaseException
     */
    public function getRecordsByFilters(array $params): array
    {
        $columns = array_keys($params);
        $placeholders = array_map(function ($value) {
            return "$value = :{$value}";
        }, $columns);

        $query = "SELECT * FROM player_logs WHERE " . implode('AND', $placeholders);
        $st = $this->databaseService->query($query, ...$params);
        return $st->fetchAll();
    }
}