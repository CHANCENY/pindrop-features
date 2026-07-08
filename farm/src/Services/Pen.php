<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Pen
{
    protected string $pen = 'pens';
    protected array $pen_columns = [
        'barn_id',
        'name',
        'capacity',
        'current_load',
        'pen_id'
    ];
    protected string $primary_key = 'pen_id';

    public function __construct(protected DatabaseService $database_service, protected LoggerInterface $logger_interface){}

    public function getPenById(string $pen_id): ?array
    {
        return $this->database_service->table($this->pen)
            ->where($this->primary_key, '=', $pen_id)
            ->first();
    }

    public function getAllPens(): array
    {
        return $this->database_service->table($this->pen)
            ->get();
    }

    public function createPen(array $pen_data): ?int
    {
        $pen_id = $this->database_service->table($this->pen)
            ->insert($pen_data);

        return $pen_id;
    }

    public function updatePen(string $pen_id, array $pen_data): bool
    {
        return $this->database_service->table($this->pen)
            ->where($this->primary_key, '=', $pen_id)
            ->update($pen_data);
    }

    public function deletePen(string $pen_id): bool
    {
        return $this->database_service->table($this->pen)
            ->where($this->primary_key, '=', $pen_id)
            ->delete();
    }

    public function getPensByBarnId(string $barn_id): array
    {
        return $this->database_service->table($this->pen)
            ->where('barn_id', '=', $barn_id)
            ->get();
    }

    public function getPenCountByBarnId(string $barn_id): int
    {
        return $this->database_service->table($this->pen)
            ->where('barn_id', '=', $barn_id)
            ->count();
    }

    public function getPenByName(string $name): array {
        return $this->database_service->table($this->pen)
        ->where('name', '=', $name)
        ->first();
    }

    public function getPens(string $text): array {
        return $this->database_service->table($this->pen)
        ->whereRaw("name LIKE '%$text%'")
        ->get();
    }
}
