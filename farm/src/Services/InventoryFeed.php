<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class InventoryFeed
{
    public function __construct(protected DatabaseService $database_service, LoggerInterface $loggerInterface) {}

    public function createSilo(array $data): ?int {
        return $this->database_service->table('feed_silos')->insert($data);
    }

    public function createFeedFormula(array $data): ?int {

        $ingredients = [];
        if (isset($data['ingredients'])) {
            $ingredients = $data['ingredients'];
            unset($data['ingredients']);
        }

        $id = $this->database_service->table('feed_formulas')->insert($data);

        if (!empty($ingredients)) {
            
            foreach ($ingredients as $ingredient) {
                $ingredient['formula_id'] = $id;
                $this->database_service->table('feed_formula_ingredients')
                ->insert($ingredient);
            }
        }
        return $id;
    }

    public function addFormulaIngredient(array $data): ?int {
        return $this->database_service->table('feed_formula_ingredients')
                ->insert($data);
    }

    public function getFormulas(): array {
        return $this->database_service->table('feed_formulas')->get();
    }

    public function getSilos(): array {
        return $this->database_service->table('feed_silos')->get();
    }

    public function getFormulaIngredients(int $formula_id): array {
        return $this->database_service->table('feed_formula_ingredients')->where('formula_id', '=', $formula_id)->get();
    }

    public function topUpSilo(int $silo_id, int $number): bool {
        $silo = $this->database_service->table('feed_silos')->where('silo_id', '=', $silo_id)->first();
        if (empty($silo)) return false;

        return $this->database_service->table('feed_silos')->where('silo_id', '=', $silo_id)
        ->update(['current_level_pct'=>$silo['current_level_pct'] + $number]);
    }

    public function takeFromSilo(int $silo_id, int $number): bool {
        $silo = $this->database_service->table('feed_silos')->where('silo_id', '=', $silo_id)->first();
        if (empty($silo)) return false;

        return $this->database_service->table('feed_silos')->where('silo_id', '=', $silo_id)
        ->update(['current_level_pct'=>$silo['current_level_pct'] - $number]);
    }

    public function updateSilo(int $silo_id, array $data): bool {
        return $this->database_service->table('feed_silos')->where('silo_id','=', $silo_id)->update($data);
    }

    public function deleteFormula(int $formula_id): bool {
        return $this->database_service->table('feed_formulas')
        ->where('formula_id', '=', $formula_id)->delete();
    }

    public function deleteFormulaIngredient(int $ingredient_id): bool {
        return $this->database_service->table('feed_formula_ingredients')
        ->where('ingredient_id', '=', $ingredient_id)->delete();
    }

    public function getActiveFormulas(): array {
        return $this->database_service->table('feed_formulas')->where('status', '=', 'Active')->get();
    }

    public function getSilo(int $silo_id): array {
        return $this->database_service->table('feed_silos')->where('silo_id', '=', $silo_id)->first();
    }

    public function getFormula(int $formula_id): array {
        return $this->database_service->table('feed_formulas')->where('formula_id','=', $formula_id)->first();
    }

    public function updateFormula(int $formula_id, array $data): bool {
        return $this->database_service->table('feed_formulas')->where('formula_id', '=', $formula_id)->update($data);
    }

}
