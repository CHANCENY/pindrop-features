<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\site_identity\src\Services;

use Simp\Pindrop\Database\DatabaseService;

class SettingsService
{
    private const TABLE = 'site_identity_settings';

    private const DEFAULT_ROBOTS = "User-agent: *\nAllow: /\n";
    private const DEFAULT_ADS = '';

    public function __construct(protected DatabaseService $database) {}

    /** Always returns a full settings array — seeds row id=1 with defaults on first read. */
    public function get(): array
    {
        $row = $this->database->table(self::TABLE)->where('id', '=', 1)->first();

        if ($row) {
            return $row;
        }

        $defaults = [
            'id'               => 1,
            'site_name'        => 'My Site',
            'theme_color'      => '#0a66c2',
            'background_color' => '#f8f9fb',
            'robots_txt'       => self::DEFAULT_ROBOTS,
            'ads_txt'          => self::DEFAULT_ADS,
            'sitemap_url'      => null,
            'logo_source'      => 'none',
            'logo_text'        => null,
        ];

        $this->database->table(self::TABLE)->insertIgnore($defaults);

        return $defaults;
    }

    public function save(array $data): void
    {
        $allowed = array_intersect_key($data, array_flip([
            'site_name', 'theme_color', 'background_color', 'robots_txt', 'ads_txt', 'sitemap_url', 'logo_source', 'logo_text',
        ]));

        if (empty($allowed)) {
            return;
        }

        // Ensure the singleton row exists before updating it.
        $this->get();

        $this->database->table(self::TABLE)->where('id', '=', 1)->update($allowed);
    }

    public function robotsTxt(): string
    {
        $value = $this->get()['robots_txt'] ?? null;
        return $value !== null && $value !== '' ? $value : self::DEFAULT_ROBOTS;
    }

    public function adsTxt(): string
    {
        return (string) ($this->get()['ads_txt'] ?? self::DEFAULT_ADS);
    }
}
