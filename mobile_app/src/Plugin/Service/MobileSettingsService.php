<?php

namespace Simp\Pindrop\Modules\mobile_app\src\Plugin\Service;

use Symfony\Component\Yaml\Yaml;

class MobileSettingsService
{
    private array $settings = [];

    public function __construct()
    {
        $file = getAppContainer()->get("CONFIG") . DIRECTORY_SEPARATOR . "/mobile.settings.yml";
        if (file_exists($file)) {
            $this->settings = Yaml::parseFile($file);
        }
    }

    public function isEnabled(): bool
    {
        return $_ENV['MOBILE'] === 'TRUE' 
            && ($this->settings['engine']['enabled'] ?? false);
    }

    public function toJson(): string
    {
        return json_encode($this->settings);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // dot notation: get('theme.primary_color')
        $keys = explode('.', $key);
        $value = $this->settings;
        foreach ($keys as $k) {
            $value = $value[$k] ?? $default;
        }
        return $value;
    }
}
