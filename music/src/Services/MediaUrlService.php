<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

use Simp\Pindrop\FileSystem\FileSystemService;

/**
 * MediaUrlService
 *
 * music_tracks.audio_uri / cover_url and music_albums/artists cover/avatar
 * URLs are stored as FileSystemService stream-wrapper URIs (e.g.
 * "public://music/tracks/xyz.mp3"), not directly browser-loadable paths —
 * this converts them via the core FileSystemService::getPublicUrl()
 * (confirmed signature) at render time. Null-safe since covers are optional
 * everywhere they appear.
 */
class MediaUrlService
{
    public function __construct(protected FileSystemService $fileSystem) {}

    public function url(?string $uri): ?string
    {
        if ($uri === null || $uri === '') {
            return null;
        }

        // Already a plain browser URL (e.g. an external placeholder image
        // during development) — pass through rather than mangling it.
        if (!str_contains($uri, '://') || str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        try {
            return $this->fileSystem->getPublicUrl($uri);
        } catch (\Throwable) {
            return null;
        }
    }
}
