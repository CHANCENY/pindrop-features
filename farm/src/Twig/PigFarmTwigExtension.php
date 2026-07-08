<?php

namespace Simp\Pindrop\Modules\farm\src\Twig;


use Simp\Pindrop\Modules\cron\src\Plugin\Twig\TwigExtension;
use Throwable;
use Twig\TwigFilter;

class PigFarmTwigExtension extends TwigExtension
{

    public function getFunctions(): array
    {
        return [];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('weeks_age', [$this, 'ageWeeks']),
            new TwigFilter('key_ingredients', [$this, 'getIngrendients']),
            new TwigFilter('silo_current_percentage', [$this, 'getSiloCurrentPercentage']),
            new TwigFilter('figure_to_currency', [$this, 'figureToCurrency']),
            new TwigFilter('round_up',[$this, 'roundUp'])
        ];
    }

    public function ageWeeks(?string $date)
    {
        if (empty($date))
            return "";

        try {

            $birthDate = new \DateTime($date);
            $today = new \DateTime();

            return intdiv($birthDate->diff($today)->days, 7);

        } catch (Throwable) {
            return "";
        }
    }

    public function getIngrendients(int $formula_id): string
    {
        $ingrendients = getAppContainer()->get('farm.invetory.feed')->getFormulaIngredients($formula_id);
        
        return implode(", ", array_column($ingrendients, 'ingredient_name'));
    }

    public function getSiloCurrentPercentage(int $silo_id): string
    {
        $silo = getAppContainer()->get('farm.invetory.feed')->getSilo($silo_id);
        if (empty($silo))
            return "";

        $capacity = (float) $silo['capacity_tons'];
        $currentContent = (float) $silo['current_level_pct'];

        // Remaining empty space
        $remainingSpace = max(0, $capacity - $currentContent);

        // Fill percentage
        $fillPercentage = $capacity > 0
            ? ($currentContent / $capacity) * 100
            : 0;

        return $fillPercentage;

    }

    public function figureToCurrency($amount): string {
        if (empty($amount)) return "";

        return ($_ENV['CURRENCY'] ?? "mwk"). number_format($amount, 2, ',');
    }

    public function roundUp($figure): int {
        if (empty($figure)) return 0;
        return ceil($figure);
    }
}
