<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\Preference as PreferenceConstant;
use Simp\Pindrop\Modules\finance_tracking\src\PreferenceActions\WeeklyMoneyNoteEmail;

class Preference
{
    public function __construct(protected readonly DatabaseService $databaseService, protected array $preference)
    {
        if (is_string($preference['preference_rules'])) {
            $this->preference['preference_rules'] = json_decode($preference['preference_rules'], true);
        }
    }

    public function getName(): string
    {
        return $this->preference["preference_name"];
    }

    public function getIsEnabled(): bool
    {
        return (bool) $this->preference["is_enabled"];
    }

    public function getPreferenceOwner(): int
    {
        return $this->preference["preference_owner"];
    }

    public function getPreferenceRules(): array
    {
        return json_decode($this->preference["preference_rules"], true);
    }

    public function save(): bool
    {

        if (is_string($this->preference["preference_rules"])) {
            $this->preference['preference_rules'] = json_decode($this->preference['preference_rules'], true);
        }

        if ($this->isEdit())
        {
            return $this->databaseService->table("preference")
            ->where("preference_name",'=', $this->getName())
            ->where('preference_owner','=', $this->getPreferenceOwner())
            ->update(['preference_rules'=> $this->getPreferenceRules(), 'is_enabled'=>$this->getIsEnabled()])
            > 0;
        } 
        
        $this->preference['preference_rules'] = json_encode($this->preference['preference_rules']);
        return $this->databaseService->table('preference')
        ->insert($this->preference) > 0;
    }

    private function isEdit(): bool
    {
        return $this->databaseService->table('preference')
        ->where($this->preference['preference_name'],'=', $this->getName())
        ->where($this->preference['preference_owner'],'=',$this->getPreferenceOwner())
        ->count() > 0;
    }

    public function performPreferenceAction(): void
    {
        // implement code for action eg send email.
        if ($this->getName() === PreferenceConstant::WEEKLY_MONEY_NOTE) {
            WeeklyMoneyNoteEmail::execute($this);
        }
    }

    public static function isPreferenceEnabled(string $preference_name, int $preference_owner): bool
    {
        return getAppContainer()->get('database')->table('preference')
        ->where('preference_name','=', $preference_name)
        ->where('preference_owner','=', $preference_owner)
        ->where('is_enabled','=', true)
        ->count() > 0;
    }


}