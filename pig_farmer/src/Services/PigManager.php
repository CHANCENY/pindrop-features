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
        return $this->db->fetchAll("SELECT * FROM pig_farmer_pigs ORDER BY created_at DESC");
    }

    /**
     * @throws DatabaseException
     */
    public function getPigById(int $id): ?array
    {
        return $this->db->fetch("SELECT * FROM pig_farmer_pigs WHERE id = ?", ...$i=[$id]);
    }

    /**
     * @throws DatabaseException
     */
    public function addPig(array $data): bool
    {
        $query = "INSERT INTO pig_farmer_pigs (tag_number, breed, gender, birth_date, status, weight) VALUES (?, ?, ?, ?, ?, ?)";
        return $this->db->query($query, ...$i=[
            $data['tag_number'],
            $data['breed'],
            $data['gender'],
            $data['birth_date'],
            $data['status'] ?? 'Active',
            $data['weight']
        ])->rowCount() > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function updatePig(int $id, array $data): bool
    {
        $query = "UPDATE pig_farmer_pigs SET tag_number = ?, breed = ?, gender = ?, birth_date = ?, status = ?, weight = ? WHERE id = ?";
        return $this->db->query($query, ...$i=[
            $data['tag_number'],
            $data['breed'],
            $data['gender'],
            $data['birth_date'],
            $data['status'],
            $data['weight'],
            $id
        ])->rowCount() > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function deletePig(int $id): bool
    {
        return $this->db->query("DELETE FROM pig_farmer_pigs WHERE id = ?", ...$i=[$id])->rowCount() > 0;
    }
}
