<?php

namespace Simp\Pindrop\Modules\admin\src\Plugin;

use Simp\Pindrop\FactorAuthentication\TwoFactorManager;
use Simp\Pindrop\Settings\SettingsInterface;

class TwoFactorSettings implements SettingsInterface
{
    public function settingKey(): string
    {
        return 'two_factor';
    }

    public function formBuild(\Symfony\Component\HttpFoundation\Request $request, ?\Simp\Pindrop\Settings\Setting $setting): string
    {
        $twoFactorManager = new TwoFactorManager(getAppContainer()->get('plugin.manager'));

        return getAppContainer()->get('twig')->render("@admin/twofactor/form.html.twig", 
        ['settings' => $setting?->getValue() ?? [], 'two_factor_providers'=> $twoFactorManager->getTwofactorAuthenticationProviders()]
        );
    }

    public function savableValues(\Symfony\Component\HttpFoundation\Request $request): array
    {
        return [
            'two_factor_key' => $request->request->get('two_factor_key'),
            'is_enabled'     => $request->request->get('is_enabled'),
        ];
    }
}
