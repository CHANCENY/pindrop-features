<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use DateTime;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Insemination
{
    protected string $insemination = "inseminations";
    protected array  $insemination_columns = [
        'insemination_id',
        'sow_id',
        'boar_id',
        'semen_batch',
        'insemination_date',
        'method',
        'location_label',
        'technician',
        'expected_due_date',
        'status',
        'notes',
        'created_at'
    ];
    protected array $method = ['Natural Mating','Artificial Insemination'];
    protected array $status = ['Confirmed','Imminent','Not Pregnant','Failed'];

    public function __construct(protected DatabaseService $database_service, LoggerInterface $loggerInterface) {}

    public function getAllInseminations(): array {
        return $this->database_service->table($this->insemination)->get();
    }

    public function createInsemination(array $data): ?int {

       if (isset($data['methood']) && !in_array($data['method'], $this->method)){
        return null;
       }

       if (isset($data['status']) && !in_array($data['status'], $this->status)){
        return null;
       }

       return $this->database_service->table($this->insemination)->insert($data);
    }

    public function updateInsemination(int $id, array $data): bool {
       
       if (isset($data['methood']) && !in_array($data['method'], $this->method)){
        return false;
       }

       if (isset($data['status']) && !in_array($data['status'], $this->status)){
        return false;
       }

       return $this->database_service->table($this->insemination)->where('insemination_id','=', $id)->update($data);
    }

    public function deleteInsemination(int $id): bool {
        return $this->database_service->table($this->insemination)->where('insemination_id','=', $id)->delete();
    }

    public function getInseminationBySow(string $sow_id): array {
        return $this->database_service->table($this->insemination)->where('sow_id','=',$sow_id)->get();
    }

    public function getInseminationByBoar(string $boar_id): array {
        return $this->database_service->table($this->insemination)->where('sow_id','=',$boar_id)->get();
    }

    public function getInseminationById(int $id): ?array {
        return $this->database_service->table($this->insemination)->where('insemination_id', '=', $id)->first();
    }

    public function getInseminationStatics(): array {
        
        $now = new DateTime();
        $thisWeekEnd   = (clone $now)->modify('sunday this week')->setTime(23, 59, 59)->format('Y-m-d'); 
        return [
            'pregnant' => $this->database_service->table($this->insemination)->whereIn('status', ['Confirmed', 'Imminent'])->count('insemination_id'),
            'due_this_week' => $this->database_service->table($this->insemination)->where('expected_due_date', '<=', $thisWeekEnd)->count('insemination_id'),
            'barn_focus'    => $this->database_service->table($this->insemination)
            ->where('status','=', 'Imminent')
            ->where('expected_due_date', '<=', $thisWeekEnd)
            ->orderBy('expected_due_date', 'ASC')
            ->first()['location_label'] ?? null,
        ];
    }

    public function addFarrowing(array $data): ?int {
        return $this->database_service->table('farrowings')->insert($data);
    }

    public function getFarrowingByInsemination(int $insemination_id): ?array {
        return $this->database_service->table('farrowings')->where('insemination_id', '=', $insemination_id)->first();
    }

    public function addPigletRecord(string $pig_id, int $farrowing_id): ? int {
        return $this->database_service->table('farrowing_piglets')
        ->insert([
            'farrowing_id' => $farrowing_id,
            'pig_id'       => $pig_id     
        ]);
    }

    public function getPigletsByFarrowingId(int $farrowing_id): array {
        $piglets = $this->database_service->table('farrowing_piglets')
        ->where('farrowing_id', '=', $farrowing_id)->get();

        $pigs = array_map(function ($item) {
            return $item['pig_id'];
        }, $piglets);

        return $this->database_service->table('pigs')->whereIn('pig_id', $pigs)->get();

    }

    public function getBreedingHistory(string $pig_id): array {

        return $this->database_service->table($this->insemination)
        ->select(["{$this->insemination}.method","{$this->insemination}.insemination_date",
        "{$this->insemination}.boar_id", "farrowings.farrowing_date","farrowings.piglets_alive",
         "{$this->insemination}.sow_id","{$this->insemination}.status"])
        ->leftJoin("farrowings", "farrowings.insemination_id","=","{$this->insemination}.insemination_id")
        ->where("{$this->insemination}.sow_id","=", $pig_id)
        ->orWhere("boar_id","=", $pig_id)
        ->orderBy("{$this->insemination}.insemination_date", "ASC")
        ->orderBy("farrowings.farrowing_date","ASC")
        ->get();
    }
}
