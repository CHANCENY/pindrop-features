<?php

namespace Simp\Pindrop\Modules\pig_farmer\src\Services;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class PigManager
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
    public function getAllPigs(): array
    {
        return $this->db->table('pig_farmer_pigs')
            ->latest('created_at')
            ->get();
    }

    /**
     * @throws DatabaseException
     */
    public function getPigById(int $id): ?array
    {
        return $this->db->table('pig_farmer_pigs')
            ->where('id', '=', $id)
            ->first();
    }

    /**
     * @throws DatabaseException
     */
    public function addPig(array $data): bool
    {
        $id = $this->db->table('pig_farmer_pigs')->insert([
            'tag_number' => $data['tag_number'],
            'breed'      => $data['breed'],
            'gender'     => $data['gender'],
            'birth_date' => $data['birth_date'],
            'status'     => $data['status'] ?? 'Active',
            'weight'     => $data['weight'],
        ]);
        return $id > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function updatePig(int $id, array $data): bool
    {
        return $this->db->table('pig_farmer_pigs')
            ->where('id', '=', $id)
            ->update([
                'tag_number' => $data['tag_number'],
                'breed'      => $data['breed'],
                'gender'     => $data['gender'],
                'birth_date' => $data['birth_date'],
                'status'     => $data['status'],
                'weight'     => $data['weight'],
            ]) > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function deletePig(int $id): bool
    {
        return $this->db->table('pig_farmer_pigs')
            ->where('id', '=', $id)
            ->delete() > 0;
    }
}
