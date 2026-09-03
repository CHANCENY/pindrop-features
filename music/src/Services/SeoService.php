<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

/**
 * SeoService
 *
 * Pure helper, no DB access — same shape as the qa plugin's SeoService,
 * built with that plugin's Search-Console lessons already applied: every
 * Person/Organization node here gets both name and identifying URL from
 * the start (not patched in after a structured-data warning), and no
 * array_filter() is used to build the JSON-LD payloads, since that
 * silently strips legitimate falsy values like a 0 play count or 0
 * duration — see the qa plugin's SeoService changelog for the incident
 * that taught this the hard way.
 */
class SeoService
{
    public function trackMetaTags(array $track, array $artist, string $baseUrl): array
    {
        $title = $track['title'] . ' by ' . $artist['name'] . ' — Music';
        $description = $track['title'] . ' by ' . $artist['name'] . '. Listen now.';
        $canonical = rtrim($baseUrl, '/') . '/music/artist/' . $artist['slug'] . '/track/' . $track['slug'];

        return $this->buildMetaSet($title, $description, $canonical, $track['cover_url'] ?? null);
    }

    public function albumMetaTags(array $album, array $artist, string $baseUrl): array
    {
        $title = $album['title'] . ' by ' . $artist['name'] . ' — Music';
        $description = 'Listen to ' . $album['title'] . ' by ' . $artist['name'] . '.';
        $canonical = rtrim($baseUrl, '/') . '/music/artist/' . $artist['slug'] . '/album/' . $album['slug'];

        return $this->buildMetaSet($title, $description, $canonical, $album['cover_url'] ?? null);
    }

    public function artistMetaTags(array $artist, string $baseUrl): array
    {
        $title = $artist['name'] . ' — Music';
        $description = $artist['bio'] ?: ('Listen to ' . $artist['name'] . ' on Music.');
        $canonical = rtrim($baseUrl, '/') . '/music/artist/' . $artist['slug'];

        return $this->buildMetaSet($title, $description, $canonical, $artist['avatar_url'] ?? null);
    }

    private function buildMetaSet(string $title, string $description, string $canonical, ?string $image): array
    {
        return [
            'title'       => $title,
            'description' => $description,
            'canonical'   => $canonical,
            'og' => array_filter([
                'og:type'        => 'music.song',
                'og:title'       => $title,
                'og:description' => $description,
                'og:url'         => $canonical,
                'og:image'       => $image,
            ]),
            'twitter' => array_filter([
                'twitter:card'        => $image ? 'summary_large_image' : 'summary',
                'twitter:title'       => $title,
                'twitter:description' => $description,
                'twitter:image'       => $image,
            ]),
        ];
    }

    /**
     * schema.org MusicRecording — the array_filter() note in this class's
     * docblock applies to counts/durations, not to this top-level
     * array_filter() call, which only strips genuinely-absent optional
     * nodes (album/genre may legitimately not exist) — duration and play
     * count are set explicitly below, never left to a blanket filter.
     */
    public function trackJsonLd(array $track, array $artist, ?array $album, string $baseUrl): array
    {
        $canonical = rtrim($baseUrl, '/') . '/music/artist/' . $artist['slug'] . '/track/' . $track['slug'];

        $node = [
            '@context'      => 'https://schema.org',
            '@type'         => 'MusicRecording',
            'name'          => $track['title'],
            'url'           => $canonical,
            'duration'      => $this->toIsoDuration((int) ($track['duration_seconds'] ?? 0)),
            'interactionStatistic' => [
                '@type'                => 'InteractionCounter',
                'interactionType'      => 'https://schema.org/ListenAction',
                'userInteractionCount' => (int) ($track['plays_count'] ?? 0),
            ],
            'byArtist' => [
                '@type' => 'MusicGroup',
                'name'  => $artist['name'],
                'url'   => rtrim($baseUrl, '/') . '/music/artist/' . $artist['slug'],
            ],
        ];

        if ($album) {
            $node['inAlbum'] = [
                '@type' => 'MusicAlbum',
                'name'  => $album['title'],
                'url'   => rtrim($baseUrl, '/') . '/music/artist/' . $artist['slug'] . '/album/' . $album['slug'],
            ];
        }

        if (!empty($track['genre'])) {
            $node['genre'] = $track['genre'];
        }

        return $node;
    }

    public function albumJsonLd(array $album, array $artist, array $tracks, string $baseUrl): array
    {
        $canonical = rtrim($baseUrl, '/') . '/music/artist/' . $artist['slug'] . '/album/' . $album['slug'];

        $trackNodes = [];
        foreach ($tracks as $t) {
            $trackNodes[] = [
                '@type'    => 'MusicRecording',
                'name'     => $t['title'],
                'url'      => $canonical . '#track-' . $t['id'],
                'duration' => $this->toIsoDuration((int) ($t['duration_seconds'] ?? 0)),
            ];
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'MusicAlbum',
            'name'       => $album['title'],
            'url'        => $canonical,
            'numTracks'  => count($tracks),
            'byArtist' => [
                '@type' => 'MusicGroup',
                'name'  => $artist['name'],
                'url'   => rtrim($baseUrl, '/') . '/music/artist/' . $artist['slug'],
            ],
            'track' => $trackNodes,
        ];
    }

    public function artistJsonLd(array $artist, string $baseUrl): array
    {
        return [
            '@context'      => 'https://schema.org',
            '@type'         => 'MusicGroup',
            'name'          => $artist['name'],
            'url'           => rtrim($baseUrl, '/') . '/music/artist/' . $artist['slug'],
            'description'   => $artist['bio'] ?? '',
            'interactionStatistic' => [
                '@type'                => 'InteractionCounter',
                'interactionType'      => 'https://schema.org/FollowAction',
                'userInteractionCount' => (int) ($artist['follower_count'] ?? 0),
            ],
        ];
    }

    private function toIsoDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;
        return 'PT' . $minutes . 'M' . $secs . 'S';
    }

    /**
     * Wraps a JSON-LD array in a literal <script> tag as a ready-to-embed
     * HTML string. Deliberately does NOT pass JSON_UNESCAPED_SLASHES
     * (unlike TrackPresenterService's data-attribute JSON elsewhere in
     * this plugin) — leaving `/` escaped as `\/` is what prevents a
     * `</script>` appearing inside a track title or lyrics field from
     * prematurely closing the tag early and breaking out into the
     * surrounding HTML.
     */
    public function jsonLdScriptTag(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
