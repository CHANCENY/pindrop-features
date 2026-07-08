<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class PigWeightRecord
{
    protected string $weight = "weight_records";
    protected array $weight_columns = [
        'weight_record_id',
        'pig_id',
        'weight_kg',
        'recorded_date'
    ];
    protected string $primary_key = "weight_record_id";

    public function __construct(protected DatabaseService $database_service, LoggerInterface $loggerInterface) {}

    public function getAllWeights(): array {
        return $this->database_service->table($this->weight)->get();
    }

    public function createWeightRecord(array $data): ?int {
        return $this->database_service->table($this->weight)->insert($data);
    }

    public function getPigWeightRecords(string $pig_id): array {
        return $this->database_service->table($this->weight)->where('pig_id', '=', $pig_id)->get();
    }

    public function deleteWeightRecord(int $id) {
        return $this->database_service->table($this->weight)->where($this->primary_key,'=', $id)->delete();
    }

    public function updateWeightRecord(int $id, array $data) {
        return $this->database_service->table($this->weight)->where($this->primary_key, '=', $id)->update($data);
    }
}
