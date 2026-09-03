<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\music\src\Services\AlbumService;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;
use Simp\Pindrop\Modules\music\src\Services\SeoService;
use Simp\Pindrop\Modules\music\src\Services\TrackPresenterService;
use Simp\Pindrop\Modules\music\src\Services\TrackService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AlbumController extends ControllerBase
{
    public function __construct(
        protected AlbumService $albums,
        protected ArtistService $artists,
        protected TrackService $tracks,
        protected MediaUrlService $mediaUrl,
        protected TrackPresenterService $presenter,
        protected SeoService $seo
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.album'),
            $container->get('music.artist'),
            $container->get('music.track'),
            $container->get('music.media_url'),
            $container->get('music.track_presenter'),
            $container->get('music.seo'),
        );
    }

    /** GET /music/artist/{artistSlug}/album/{albumSlug} */
    public function view(Request $request, string $route_name, array $options): Response
    {
        $artistSlug = (string) $request->query->get('artistSlug');
        $albumSlug = (string) $request->query->get('albumSlug');

        $artist = $this->artists->findBySlug($artistSlug);
        if (!$artist) {
            return $this->renderTwig('@music/404.html.twig', [], 404);
        }

        $album = $this->albums->findByArtistAndSlug((int) $artist['id'], $albumSlug);
        if (!$album) {
            return $this->renderTwig('@music/404.html.twig', [], 404);
        }

        $tracks = $this->tracks->forAlbum((int) $album['id']);
        $playPayloads = [];
        foreach ($tracks as &$track) {
            if (empty($track['cover_url'])) {
                $album = $this->albums->find((int) $track['album_id']);
                $track['cover_url'] = $album['cover_url'] ?? null;
            }
            $track['_cover'] = $this->mediaUrl->url($track['cover_url'] ?? null) ?? $this->mediaUrl->url($album['cover_url'] ?? null);
            $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist);
            $playPayloads[] = $this->presenter->present($track, $artist);
        }
        unset($track);

        $totalSeconds = array_sum(array_column($tracks, 'duration_seconds'));

        return $this->renderTwig('@music/album.html.twig', [
            'artist'         => $artist,
            'album'          => $album,
            'cover'          => $this->mediaUrl->url($album['cover_url'] ?? null),
            'tracks'         => $tracks,
            'total_duration' => (int) $totalSeconds,
            'play_all_json'  => htmlspecialchars(json_encode($playPayloads, JSON_UNESCAPED_SLASHES) ?: '[]', ENT_QUOTES),
            'seo_meta'       => $this->seo->albumMetaTags($album, $artist, $request->getSchemeAndHttpHost()),
            'json_ld_script' => $this->seo->jsonLdScriptTag(
                $this->seo->albumJsonLd($album, $artist, $tracks, $request->getSchemeAndHttpHost())
            ),
            'meta' => [
                'title'       => $album['title'] . ' by ' . $artist['name'] . ' — Music',
                'description' => 'Listen to ' . $album['title'] . ' by ' . $artist['name'] . '.',
            ],
        ]);
    }
}
