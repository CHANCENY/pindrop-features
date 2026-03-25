<?php

namespace Simp\Pindrop\Modules\streamer\src\Config;

use Simp\Pindrop\Plugin\PluginManager;

class Config
{
    protected array $configs = [];

    private ?string $apiKey = null;

    public function __construct(protected PluginManager $pluginManager)
    {
        $this->configs = $this->pluginManager->getPluginYamlContent('streamer', "tmdb.config");
        $this->apiKey = \getAppContainer()->get('TMDB_API_KEY');
    }

    public function getApiKey(): ?string {
        return $this->apiKey;
    }

    public function getConfigs(): array
    {
        return $this->configs;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $configs = $this->getConfigs();

        if ($key === '') {
            return $configs;
        }

        $segments = explode('.', $key);

        foreach ($segments as $segment) {
            if (!is_array($configs) || !array_key_exists($segment, $configs)) {
                return $default;
            }

            $configs = $configs[$segment];
        }

        return $configs;
    }
}