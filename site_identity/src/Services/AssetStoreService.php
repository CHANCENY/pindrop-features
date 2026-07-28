<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\site_identity\src\Services;

use Simp\Pindrop\Database\DatabaseService;

class AssetStoreService
{
    private const TABLE = 'site_identity_assets';

    /** Canonical variant keys this plugin knows how to serve. */
    public const VARIANTS = [
        'favicon.svg', 'favicon.ico', 'favicon-96x96.png',
        'apple-touch-icon.png', 'icon-192.png', 'icon-512.png',
    ];

    public function __construct(protected DatabaseService $database) {}

    public function get(string $variant): ?array
    {
        return $this->database->table(self::TABLE)->where('variant', '=', $variant)->first();
    }

    public function put(string $variant, string $bytes, string $mimeType): void
    {
        $this->database->table(self::TABLE)->upsert(
            ['variant' => $variant, 'mime_type' => $mimeType, 'data' => $bytes],
            ['mime_type', 'data']
        );
    }

    /** @param array<string,array{bytes:string,mime:string}> $assets keyed by variant */
    public function putMany(array $assets): void
    {
        foreach ($assets as $variant => $asset) {
            $this->put($variant, $asset['bytes'], $asset['mime']);
        }
    }

    public function hasAny(): bool
    {
        return $this->database->table(self::TABLE)->exists();
    }
}
