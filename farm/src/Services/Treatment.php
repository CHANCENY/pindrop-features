<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Treatment
{
    protected string $treatment = 'treatments';
    protected array $pig_columns = [
        'treatment_id',
        'pig_id',
        'animal_group',
        'diagnosis',
        'treatment',
        'dosage',
        'treatment_date',
        'duration_days',
        'attending_vet',
        'outcome',
        'notes',
        'created_at',
    ];
    protected string $primary_key = 'treatment_id';
    protected array $outcome = ['Under Treatment','Recovered','Ongoing Monitoring','Deceased'];

    public function __construct(protected DatabaseService $database_service, LoggerInterface $loggerInterface) {}

    public function getAllTreatments(): array {
        return $this->database_service->table($this->treatment)->get();
    }

    public function createTreatment(array $data): ?int {
        if (isset($data['outcome']) && !in_array($data['outcome'], $this->outcome)) {
            return null;
        }

        return $this->database_service->table($this->treatment)->insert($data);
    }

    public function updateTreatment(int $id, array $data): bool {
         if (isset($data['outcome']) && !in_array($data['outcome'], $this->outcome)) {
            return false;
        }
        return $this->database_service->table($this->treatment)->where($this->primary_key, '=', $id)->update($data);

    }

    public function getPigTreatments(string $pig_id): array {
        return $this->database_service->table($this->treatment)->where('pig_id', '=', $pig_id)->get();
    }
    
}
