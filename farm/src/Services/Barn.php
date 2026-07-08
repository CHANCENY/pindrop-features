<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Barn
{
    protected string $barn = "barns";
    protected array $barn_columns = [
        'barn_id',
        'facility_id',
        'name',
        'capacity',
        'current_load',
        'status',
        'created_at',
        'updated_at',
        'pens_count'
    ];
    protected string $primary_key = 'barn_id';
    protected array $barn_status = [
        'Operational',
        'Maintenance', 
        'Decommissioned' 
    ];


    public function __construct(protected DatabaseService $database_service, protected LoggerInterface $logger_interface){}

    public function getBarnById(string $barn_id): ?array
    {
        return $this->database_service->table($this->barn)
            ->where($this->primary_key, '=', $barn_id)
            ->first();
    }

    public function getAllBarns(): array
    {
        return $this->database_service->table($this->barn)
            ->get();
    }

    public function createBarn(array $barn_data): ?int
    {
        if (isset($barn_data['status']) && in_array($barn_data['status'], $this->barn_status) === false) {
            $this->logger_interface->error("Invalid barn status: " . $barn_data['status']);
            return null;
        }
        $barn_id = $this->database_service->table($this->barn)
            ->insert($barn_data);

        return $barn_id;
    }

    public function updateBarn(string $barn_id, array $barn_data): bool
    {
        if (isset($barn_data['status']) && in_array($barn_data['status'], $this->barn_status) === false) {
            $this->logger_interface->error("Invalid barn status: " . $barn_data['status']);
            return false;
        }
        return $this->database_service->table($this->barn)
            ->where($this->primary_key, '=', $barn_id)
            ->update($barn_data);
    }

    public function deleteBarn(string $barn_id): bool
    {
        return $this->database_service->table($this->barn)
            ->where($this->primary_key, '=', $barn_id)
            ->delete();
    }

    public function getBarnStatus(): array
    {
        return $this->barn_status;
    }

    public function getBarnsByFacilityId(string $facility_id): array
    {
        return $this->database_service->table($this->barn)
            ->where('facility_id', '=', $facility_id)
            ->get();
    }

    public function getBarnByName(string $name): array {
        return $this->database_service->table($this->barn)
        ->where('name', '=', $name)
        ->first();
    }
    
}
