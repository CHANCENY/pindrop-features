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
        return $this->db->table('pig_farmer_health_records')
            ->where('pig_id', '=', $pigId)
            ->latest('checkup_date')
            ->get();
    }

    /**
     * @throws DatabaseException
     */
    public function addHealthRecord(array $data): bool
    {
        $id = $this->db->table('pig_farmer_health_records')->insert([
            'pig_id'       => $data['pig_id'],
            'checkup_date' => $data['checkup_date'],
            'condition'    => $data['condition'],
            'treatment'    => $data['treatment'],
            'notes'        => $data['notes'],
        ]);
        return $id > 0;
    }
}
