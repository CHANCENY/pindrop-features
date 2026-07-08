<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use DateTime;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Vaccination
{
    protected string $vaccination = 'vaccinations';
    protected array $vaccination_columns = [
        'vaccination_id',
        'animal_group',
        'vaccine_type',
        'batch_id',
        'scheduled_date',
        'assigned_to',
        'status',
        'created_at'
    ];
    protected array $status = ['Upcoming', 'Overdue', 'Completed'];
    protected array $vaccinations = [
        [
            'days' => 3,
            'vaccine' => 'Iron Injection',
            'batch' => "VC-0101"
        ],
        [
            'days' => 21,
            'vaccine' => 'Mycoplasma',
            'batch' => 'VC-0102'
        ],
        [
            'days' => 28,
            'vaccine' => 'PCV2 (Circovirus)',
            'batch' => 'VC-0103'
        ],
        [
            'days' => 42,
            'vaccine' => 'Erysipelas',
            'batch' => 'VC-0104'
        ],
        [
            'days' => 63,
            'vaccine' => 'Erysipelas Booster',
            'batch' => 'VC-0105'
        ]
    ];

    public function __construct(protected DatabaseService $database_service, LoggerInterface $loggerInterface)
    {
    }

    public function getVaccinationsBlueprints(): array
    {
        return $this->vaccinations;
    }

    public function addVaccinationBatch(string $animal_batch_id, ?string $batch_birth_date = null): ?int {

        $birthDate = new DateTime(
            $batch_birth_date ?? date('Y-m-d')
        );

        $records = $this->generateSchedule(
            $animal_batch_id,
            $birthDate
        );

        foreach ($records as $record) {
            $this->database_service->table($this->vaccination)->insert($record);
        }

        return count($records);
    }

    private function generateSchedule(string $animalBatchId, DateTime $birthDate): array
    {
        $records = [];

        foreach ($this->vaccinations as $vaccination) {

            $date = clone $birthDate;
            $date->modify("+{$vaccination['days']} days");

            $records[] = [
                'animal_group' => $animalBatchId,
                'vaccine_type' => $vaccination['vaccine'],
                'batch_id' => $vaccination['batch'],
                'scheduled_date' => $date->format('Y-m-d'),
                'assigned_to' => null,
                'status' => $date < new DateTime()
                    ? 'Overdue'
                    : 'Upcoming'
            ];
        }

        return $records;
    }

    public function getBatchVaccinationsSchedules(string $animal_group): array {
        return $this->database_service->table($this->vaccination)
        ->where("animal_group", "=", $animal_group)
        ->orderBy('scheduled_date', 'ASC')
        ->get();
    }

    public function getAllVaccinations(): array {
        return $this->database_service->table($this->vaccination)->get();
    }

    public function isAnimalGroupVaccinationSchedulesExists(string $animal_group): bool {
        return $this->database_service->table($this->vaccination)->where('animal_group', '=', $animal_group)
         ->count('vaccination_id');
    }

    public function addAnimalToVaccinationGroup(int $animal_group, string $pig_id): ?int {
        return $this->database_service->table('vaccination_group_pigs')
        ->where('pig_id', '=', $pig_id)
        ->count('vgid') === 0 ?
        
        $this->database_service->table('vaccination_group_pigs')
        ->insert(['animal_group'=>$animal_group, 'pig_id'=>$pig_id]) : null;
    }

    public function getAnimalOnGroup(string $group): array {
        return $this->database_service->table('vaccination_group_pigs')->select(['pig_id'])
        ->where('animal_group', '=', $group)->get();
    }

    public function updateVaccination(int $id, string $vet, string $status): bool {
        return $this->database_service->table($this->vaccination)->where('vaccination_id', '=', $id)
        ->update(['assigned_to'=>$vet, 'status'=>$status]);
    }

    public function getOverallStatics(): array {
        return [
            'upcoming'=> $this->database_service->table($this->vaccination)->where('status', '=', 'Upcoming')->count('vaccination_id'),
            'overdue' => $this->database_service->table($this->vaccination)->where('status','=', 'Overdue')->count('vaccination_id'),
            'vet_visits' => $this->database_service->table($this->vaccination)->whereNotNull('assigned_to')->count('vaccination_id'),
            'next_vet_visit' => $this->database_service->table($this->vaccination)->where('status','=', 'Upcoming')
                                ->orderBy('scheduled_date', 'ASC')->first()['scheduled_date'] ?? '',
        ];
    }
}
