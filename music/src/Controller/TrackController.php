<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\LikeService;
use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;
use Simp\Pindrop\Modules\music\src\Services\PlaylistService;
use Simp\Pindrop\Modules\music\src\Services\SeoService;
use Simp\Pindrop\Modules\music\src\Services\TrackPresenterService;
use Simp\Pindrop\Modules\music\src\Services\TrackService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackController extends ControllerBase
{
    public function __construct(
        protected TrackService $tracks,
        protected ArtistService $artists,
        protected MediaUrlService $mediaUrl,
        protected TrackPresenterService $presenter,
        protected LikeService $likes,
        protected PlaylistService $playlists,
        protected SeoService $seo,
        protected CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.track'),
            $container->get('music.artist'),
            $container->get('music.media_url'),
            $container->get('music.track_presenter'),
            $container->get('music.like'),
            $container->get('music.playlist'),
            $container->get('music.seo'),
            $container->get('current_user'),
        );
    }

    /** GET /music/artist/{artistSlug}/track/{trackSlug} */
    public function view(Request $request, string $route_name, array $options): Response
    {
        $artistSlug = (string) $request->query->get('artistSlug');
        $trackSlug = (string) $request->query->get('trackSlug');

        $artist = $this->artists->findBySlug($artistSlug);
        if (!$artist) {
            return $this->renderTwig('@music/404.html.twig', [], 404);
        }

        $track = $this->tracks->findByArtistAndSlug((int) $artist['id'], $trackSlug);
        if (!$track) {
            return $this->renderTwig('@music/404.html.twig', [], 404);
        }

        $userId = $this->currentUser->getUserId();
        $isLiked = $userId ? $this->likes->isLiked((int) $userId, 'track', (int) $track['id']) : false;

        $track['_cover'] = $this->mediaUrl->url($track['cover_url'] ?? null) ?? $this->mediaUrl->url($artist['avatar_url'] ?? null);
        $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist, $isLiked);
        $track['_liked'] = $isLiked;

        $related = $this->tracks->related((int) $track['id'], $track['genre'] ?? null, (int) $artist['id'], 8);
        $relatedIds = array_map(static fn ($r) => (int) $r['id'], $related);
        $relatedLiked = $userId ? $this->likes->likedMap((int) $userId, 'track', $relatedIds) : [];

        foreach ($related as &$r) {
            $rArtist = (int) $r['artist_id'] === (int) $artist['id']
                ? $artist
                : ($this->artists->find((int) $r['artist_id']) ?? ['name' => 'Unknown Artist', 'slug' => '']);
            $r['_cover'] = $this->mediaUrl->url($r['cover_url'] ?? null);
            $r['_artist'] = $rArtist;
            $r['_play_json'] = $this->presenter->presentAsAttribute($r, $rArtist, $relatedLiked[(int) $r['id']] ?? false);
        }
        unset($r);

        return $this->renderTwig('@music/track.html.twig', [
            'artist'       => $artist,
            'track'        => $track,
            'related'      => $related,
            'my_playlists' => $userId ? $this->playlists->forUser((int) $userId) : [],
            'seo_meta'       => $this->seo->trackMetaTags($track, $artist, $request->getSchemeAndHttpHost()),
            'json_ld_script' => $this->seo->jsonLdScriptTag(
                $this->seo->trackJsonLd($track, $artist, null, $request->getSchemeAndHttpHost())
            ),
            'meta' => [
                'title'       => $track['title'] . ' — ' . $artist['name'],
                'description' => $track['title'] . ' by ' . $artist['name'] . ' — listen now.',
            ],
        ]);
    }
}
