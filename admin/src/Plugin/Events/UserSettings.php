<?php

namespace Simp\Pindrop\Modules\admin\src\Plugin\Events;

use Simp\Pindrop\Settings\Setting;
use Override;
use Simp\Pindrop\Settings\SettingsInterface;
use Symfony\Component\HttpFoundation\Request;

class UserSettings implements SettingsInterface
{
    #[Override]
    public function formBuild(Request $request, ?Setting $setting): string
    {
        return \getAppContainer()->get('twig')->render("@admin/settings/user_settings.html.twig",
        is_null($setting) ? [] : $setting?->getValue());
    }

    #[Override]
    public function savableValues(Request $request): array
    {
        return [
            'login_page' => $request->request->get('login_page'),
            'register_page' => $request->request->get('register_page'),
            'forgot_password' => $request->request->get('forgot_password'),
        ];
    }

    #[Override]
    public function settingKey(): string
    {
        return "user.admin.settings";
    }
}