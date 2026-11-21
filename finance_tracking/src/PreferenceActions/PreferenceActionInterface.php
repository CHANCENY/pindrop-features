<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\PreferenceActions;

use Simp\Pindrop\Modules\finance_tracking\src\Preference;

interface PreferenceActionInterface
{
    /**
     * Execute the action
     * @param Preference $preference
     * @return void
     */
    public static function execute(Preference $preference): void;
}