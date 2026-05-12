<?php

namespace Simp\Pindrop\Modules\mobile_app\src\Plugin\Twig;

use Simp\Pindrop\Modules\mobile_app\src\Plugin\Service\MobileSettingsService;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\Request;

class MobileTwigGlobal
{
    protected MobileSettingsService $mobileSettingsService;

    public function __construct() {
        $this->mobileSettingsService = new MobileSettingsService();
    }
    public function getGlobals(): array
    {
        $data = $this->mobileSettingsService->toJson();
        $data = json_decode($data, true);
        return [
            'mobile_app' => [
                'enabled' => $this->mobileSettingsService->isEnabled(),
                ...$data,
                'app_version' => $_ENV['APP_VERSION'] ?? '1.0',
                'user' => getAppContainer()->get('current_user')?->getUser()?->toArray() ?? null,
                'mobile_settings' => $this->mobileSettingsService->toJson(),
                'app_name' => $this->mobileSettingsService->get('pwa.app_name', $_ENV['APP_NAME'] ?? 'Pindrop App'),
                '__csrf_token' => Url::generateToken(Request::createFromGlobals(), $_ENV['CSRF_TOKEN_SECRET']),
            ],
        ];
    }

}
