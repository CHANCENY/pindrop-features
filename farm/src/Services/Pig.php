<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;
use Throwable;

class Pig
{
    protected string $pig = 'pigs';
    protected array $pig_columns = [
        'pig_id',
        'breed',
        'sex',
        'date_of_birth',
        'sire_id',
        'dam_id',
        'barn_id',
        'pen_id',
        'location_label',
        'current_weight_kg',
        'health_status',
        'notes',
        'created_at',
        'updated_at'
    ];
    protected string $primary_key = 'pig_id';
    protected array $health_statuses = ['Healthy','Quarantine','Under Observation','Deceased'];
    protected array $sex = ['Female (Sow)','Male (Boar)','Female (Gilt)','Male (Barrow)'];

    public function __construct(protected DatabaseService $database_service, LoggerInterface $loggerInterface) {}

    public function getAllPigs(): array
    {
        return $this->database_service->table($this->pig)->select($this->pig_columns)->get();
    }

    public function getPigById(string $pig_id): ?array
    {
        return $this->database_service->table($this->pig)->select($this->pig_columns)->where($this->primary_key,'=', $pig_id)->first();
    }

    public function createPig(array $data): ?int
    {
        // Validate and sanitize input data here if necessary
        if (isset($data['sex']) && !in_array($data['sex'], $this->sex)) return null;
        if (isset($data['health_status']) && !in_array($data['health_status'], $this->health_statuses)) return null;
        try{ $this->database_service->table($this->pig)->insert($data); return 1; }catch(Throwable){return null;}
    }

    public function updatePig(string $pig_id, array $data): bool
    {
        // Validate and sanitize input data here if necessary
        if (isset($data['sex']) && !in_array($data['sex'], $this->sex)) return false;
        if (isset($data['health_status']) && !in_array($data['health_status'], $this->health_statuses)) return false;
        return $this->database_service->table($this->pig)->where($this->primary_key,'=', $pig_id)->update($data);
    }

    public function deletePig(string $pig_id): bool
    {
        return $this->database_service->table($this->pig)->where($this->primary_key,'=', $pig_id)->delete();
    }

    public function getHealthStatuses(): array
    {
        return $this->health_statuses;
    }

    public function getSexes(): array
    {
        return $this->sex;  
    }

    public function getPigColumns(): array
    {
        return $this->pig_columns;
    }

    public function getPigsByBarnId(int $barn_id): array
    {
        return $this->database_service->table($this->pig)
            ->select($this->pig_columns)
            ->where('barn_id','=', $barn_id)
            ->get();
    }

    public function getPigsByPenId(int $pen_id): array
    {
        return $this->database_service->table($this->pig)
            ->select($this->pig_columns)
            ->where('pen_id','=', $pen_id)
            ->get();
    }

    public function getPigsByFacilityId(int $facility_id): array
    {
        return $this->database_service->table($this->pig)
            ->select($this->pig_columns)
            ->where('facility_id','=', $facility_id)
            ->get();
    }

    public function getPigsBySireId(int $sire_id): array
    {
        return $this->database_service->table($this->pig)
            ->select($this->pig_columns)
            ->where('sire_id','=', $sire_id)
            ->get();
    }

    public function getPigsByHealthStatus(string $health_status): array
    {
        return $this->database_service->table($this->pig)
            ->select($this->pig_columns)
            ->where('health_status','=', $health_status)
            ->get();
    }

    public function getPigsBySex(string $sex): array
    {
        return $this->database_service->table($this->pig)
            ->select($this->pig_columns)
            ->where('sex','=', $sex)
            ->get();
    }

    public function getPigsByBreed(string $breed): array
    {
        return $this->database_service->table($this->pig)
            ->select($this->pig_columns)
            ->where('breed','=', $breed)
            ->get();
    }

    public function getSirePigsByTag(string $tag, int $limit = 10, string $sort = 'DESC', string $sort_by = "created_at"): array
    {
        $tag = strtoupper($tag);
        return $this->database_service->table($this->pig)
            ->select($this->pig_columns)
            ->whereRaw("sex LIKE '%MALE%' OR breed LIKE ? OR pig_id = ?", ["%$tag%", $tag])
            ->limit($limit)
            ->orderBy($sort_by, $sort)
            ->get();
    }

    public function getDamPigsByTag(string $tag, int $limit = 10, string $sort = 'DESC', string $sort_by = "created_at"): array
    {
        $tag = strtoupper($tag);
        return $this->database_service->table($this->pig)
            ->select($this->pig_columns)
            ->whereRaw("sex LIKE '%FEMALE%' OR breed LIKE ? OR pig_id = ?", ["%$tag%", $tag])
            ->limit($limit)
            ->orderBy($sort_by, $sort)
            ->get();
    }

    public function searchPigs(array $filters): array {
         $queryBuilder = $this->database_service->table($this->pig);

        if (isset($filters['tag'])) {
            $queryBuilder->whereRaw("pig_id LIKE ?",["%{$filters['tag']}"]);
            unset($filters['tag']);
        }

        foreach($filters as $field=>$value) {
            if (!empty($value) && is_string($field)) {
                $queryBuilder->where($field, '=', $value);
            }
        }

        $queryBuilder->orderBy('created_at', 'DESC');

        return $queryBuilder->get();
    }


    public function getCount(): int {
        return $this->database_service->table($this->pig)->count();
    }

}
