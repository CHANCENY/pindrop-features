<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\music\src\Services\AlbumService;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\FollowService;
use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;
use Simp\Pindrop\Modules\music\src\Services\SeoService;
use Simp\Pindrop\Modules\music\src\Services\TrackPresenterService;
use Simp\Pindrop\Modules\music\src\Services\TrackService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ArtistController extends ControllerBase
{
    public function __construct(
        protected ArtistService $artists,
        protected AlbumService $albums,
        protected TrackService $tracks,
        protected MediaUrlService $mediaUrl,
        protected TrackPresenterService $presenter,
        protected FollowService $follows,
        protected SeoService $seo,
        protected CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.artist'),
            $container->get('music.album'),
            $container->get('music.track'),
            $container->get('music.media_url'),
            $container->get('music.track_presenter'),
            $container->get('music.follow'),
            $container->get('music.seo'),
            $container->get('current_user'),
        );
    }

    /** GET /music/artist/{slug} */
    public function view(Request $request, string $route_name, array $options): Response
    {
        $slug = (string) $request->query->get('slug');
        $artist = $this->artists->findBySlug($slug);

        if (!$artist) {
            return $this->renderTwig('@music/404.html.twig', [], 404);
        }

        $topTracks = $this->tracks->topForArtist((int) $artist['id'], 10);
        foreach ($topTracks as &$track) {
            if (empty($track['cover_url'])) {
                $album = $this->albums->find((int) $track['album_id']);
                $track['cover_url'] = $album['cover_url'] ?? null;
            }
            $track['_cover'] = $this->mediaUrl->url($track['cover_url'] ?? null) ?? $this->mediaUrl->url($artist['avatar_url'] ?? null);
            $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist);
        }
        unset($track);

        $albums = $this->albums->forArtist((int) $artist['id']);
        foreach ($albums as &$album) {
            $album['_cover'] = $this->mediaUrl->url($album['cover_url'] ?? null);
        }
        unset($album);

        $userId = $this->currentUser->getUserId();

        return $this->renderTwig('@music/artist.html.twig', [
            'artist'        => $artist,
            'avatar'        => $this->mediaUrl->url($artist['avatar_url'] ?? null),
            'top_tracks'    => $topTracks,
            'albums'        => $albums,
            'is_owner'      => $userId && (int) $artist['owner_user_id'] === (int) $userId,
            'is_following'  => $userId ? $this->follows->isFollowing((int) $userId, (int) $artist['id']) : false,
            'seo_meta'       => $this->seo->artistMetaTags($artist, $request->getSchemeAndHttpHost()),
            'json_ld_script' => $this->seo->jsonLdScriptTag($this->seo->artistJsonLd($artist, $request->getSchemeAndHttpHost())),
            'meta' => [
                'title'       => $artist['name'] . ' — Music',
                'description' => $artist['bio'] ?: ('Listen to ' . $artist['name'] . ' on Music.'),
            ],
        ]);
    }
}
