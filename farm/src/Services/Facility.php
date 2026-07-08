<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Facility
{
    protected string $facility = 'facilities';
    protected array $facility_columns = [
        'facility_id',
        'name',
        'location',
        'manager_name',
        'barns_count',
        'capacity',
        'current_load',
        'status',
        'created_at',
        'updated_at'
    ];
    protected string $primary_key = 'facility_id';
    protected array $facility_status = [
        'Operational',
        'Under Construction',
        'Maintenance',
        'Decommissioned'
    ];

    public function __construct(protected DatabaseService $database_service, protected LoggerInterface $logger_interface){}

    public function getFacilityById(string $facility_id): ?array
    {
        return $this->database_service->table($this->facility)
            ->where($this->primary_key, '=', $facility_id)
            ->first();
    }

    public function getAllFacilities(): array
    {
        return $this->database_service->table($this->facility)
            ->get();
    }

    public function createFacility(array $facility_data): ?int
    {
        if (isset($facility_data['status']) && in_array($facility_data['status'], $this->facility_status) === false) {
            $this->logger_interface->error("Invalid facility status: " . $facility_data['status']);
            return null;
        }
        $facility_id = $this->database_service->table($this->facility)
            ->insert($facility_data);

        return $facility_id;
    }

    public function updateFacility(string $facility_id, array $facility_data): bool
    {
        if (isset($facility_data['status']) && in_array($facility_data['status'], $this->facility_status) === false) {
            $this->logger_interface->error("Invalid facility status: " . $facility_data['status']);
            return false;
        }
        return $this->database_service->table($this->facility)
            ->where($this->primary_key, '=', $facility_id)
            ->update($facility_data);
    }

    public function deleteFacility(string $facility_id): bool
    {
        return $this->database_service->table($this->facility)
            ->where($this->primary_key, '=', $facility_id)
            ->delete();
    }

    public function getFacilityStatus(): array
    {
        return $this->facility_status;
    }

    public function getFacilityColumns(): array
    {
        return $this->facility_columns;
    }

    public function getPrimaryKey(): string
    {
        return $this->primary_key;
    }

    public function getFacilityTableName(): string
    {
        return $this->facility;
    }

}
