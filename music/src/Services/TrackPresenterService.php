<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

/**
 * TrackPresenterService
 *
 * Converts a raw music_tracks row (+ its artist row) into the exact JSON
 * shape music-player.js's data-play-track/data-queue-track attributes
 * expect (see the docblock at the top of assets/js/music-player.js).
 * Centralized here since every browse/search/library controller needs it —
 * keeping the shape defined in one place avoids the four controllers
 * quietly drifting out of sync with what the JS actually reads.
 */
class TrackPresenterService
{
    public function __construct(protected MediaUrlService $mediaUrl) {}

    public function present(array $track, array $artist, bool $liked = false): array
    {
        return [
            'id'        => (int) $track['id'],
            'title'     => $track['title'],
            'artist'    => $artist['name'] ?? '',
            'artistUrl' => '/music/artist/' . ($artist['slug'] ?? ''),
            'cover'     => $this->mediaUrl->url($track['cover_url'] ?? null),
            'audio'     => $this->mediaUrl->url($track['audio_uri']),
            'duration'  => (int) ($track['duration_seconds'] ?? 0),
            'url'       => '/music/artist/' . ($artist['slug'] ?? '') . '/track/' . $track['slug'],
            'liked'     => $liked,
        ];
    }

    /** JSON-encoded, HTML-attribute-safe (single quotes escaped) for embedding in data-play-track="...". */
    public function presentAsAttribute(array $track, array $artist, bool $liked = false): string
    {
        return htmlspecialchars(json_encode($this->present($track, $artist, $liked), JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES);
    }
}
