<?php

namespace Simp\Pindrop\Modules\mobile_app\src\Plugin\Twig;


use Simp\Pindrop\Modules\mobile_app\src\Plugin\Service\MobileSettingsService;
use Twig\Extension\AbstractExtension;

class MobileTwigExtension extends AbstractExtension
{
    protected MobileSettingsService $mobileSettingsService;

    public function __construct()
    {
        // Initialize the mobile settings service
        $this->mobileSettingsService = new MobileSettingsService();
    }
    public function getFunctions()
    {
        return [
            // Define your custom Twig functions here
            new \Twig\TwigFunction("mobile_settings", [$this,"mobileSettings"]),
            new \Twig\TwigFunction("mobile_enabled", [$this,"mobileEnabled"]),
        ];
    }
    public function mobileSettings()
    {
        return $this->mobileSettingsService->toJson();
    }
    public function mobileEnabled()
    {
        return $this->mobileSettingsService->isEnabled();
    }
}
