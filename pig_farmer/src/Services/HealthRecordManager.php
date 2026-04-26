<?php

namespace Simp\Pindrop\Modules\pig_farmer\src\Services;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class HealthRecordManager
{
    private DatabaseService $db;
    private $logger;

    public function __construct(DatabaseService $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * @throws DatabaseException
     */
    public function getHealthRecordsByPigId(int $pigId): array
    {
        return $this->db->fetchAll("SELECT * FROM pig_farmer_health_records WHERE pig_id = ? ORDER BY checkup_date DESC", ...$i=[$pigId]);
    }

    /**
     * @throws DatabaseException
     */
    public function addHealthRecord(array $data): bool
    {
        $query = "INSERT INTO pig_farmer_health_records (pig_id, checkup_date, `condition`, treatment, notes) VALUES (?, ?, ?, ?, ?)";
        return $this->db->query($query, ...$i=[
            $data["pig_id"],
            $data["checkup_date"],
            $data["condition"],
            $data["treatment"],
            $data["notes"]
        ])->rowCount() > 0;
    }
}
