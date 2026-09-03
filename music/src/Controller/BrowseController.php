<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\music\src\Services\AlbumService;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\ListeningHistoryService;
use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;
use Simp\Pindrop\Modules\music\src\Services\TrackPresenterService;
use Simp\Pindrop\Modules\music\src\Services\TrackService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class BrowseController extends ControllerBase
{
    public function __construct(
        protected TrackService $tracks,
        protected AlbumService $albums,
        protected ArtistService $artists,
        protected MediaUrlService $mediaUrl,
        protected TrackPresenterService $presenter,
        protected ListeningHistoryService $history,
        protected CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.track'),
            $container->get('music.album'),
            $container->get('music.artist'),
            $container->get('music.media_url'),
            $container->get('music.track_presenter'),
            $container->get('music.history'),
            $container->get('current_user'),
        );
    }

    /** GET /music */
    public function index(Request $request, string $route_name, array $options): Response
    {
        $trending = $this->enrichTracks($this->tracks->trending(12));
        $recent = $this->enrichTracks($this->tracks->recentlyAdded(12));
        $popularArtists = $this->prepareArtists($this->artists->popular(10));
        $recentAlbums = $this->prepareAlbums($this->albums->recent(10));
        $madeForYou = $this->buildMadeForYou();

        return $this->renderTwig('@music/home.html.twig', [
            'trending'          => $trending,
            'recently_added'    => $recent,
            'popular_artists'   => $popularArtists,
            'recent_albums'     => $recentAlbums,
            'made_for_you'      => $madeForYou,
            'meta' => [
                'title'       => 'Music',
                'description' => 'Listen to trending tracks, new albums, and popular artists.',
            ],
        ]);
    }

    /**
     * Rule-based "Made For You": look at the user's most recently played
     * track and surface tracks related to it (same genre, or same artist
     * as a fallback — see TrackService::related()). Deliberately simple
     * (no ML, no weighting across multiple history entries) — the build
     * plan scoped v1 recommendations as rule-based on purpose. Empty for
     * logged-out visitors or users with no listening history yet.
     */
    private function buildMadeForYou(): array
    {
        $userId = $this->currentUser->getUserId();
        if (!$userId) {
            return [];
        }

        $recentIds = $this->history->recentTrackIdsForUser((int) $userId, 1);
        if (empty($recentIds)) {
            return [];
        }

        $seed = $this->tracks->find($recentIds[0]);
        if (!$seed) {
            return [];
        }

        $related = $this->tracks->related((int) $seed['id'], $seed['genre'] ?? null, (int) $seed['artist_id'], 12);
        return $this->enrichTracks($related);
    }

    /**
     * Attaches artist rows + a ready-to-embed play payload to each track,
     * since Twig templates loop over these and need both without doing
     * per-row DB lookups from within the template.
     */
    private function enrichTracks(array $tracks): array
    {
        if (empty($tracks)) {
            return [];
        }

        $artistIds = array_unique(array_map(static fn ($t) => (int) $t['artist_id'], $tracks));
        $artistsById = [];
        foreach ($artistIds as $id) {
            $artist = $this->artists->find($id);
            if ($artist) {
                $artistsById[$id] = $artist;
            }
        }

        foreach ($tracks as &$track) {
            $artist = $artistsById[(int) $track['artist_id']] ?? ['name' => 'Unknown Artist', 'slug' => ''];
            $track['_artist'] = $artist;
            if (empty($track['cover_url'])) {
                $album = $this->albums->find((int) $track['album_id']);
                $track['cover_url'] = $album['cover_url'] ?? null;
            }
            $track['_cover'] = $this->mediaUrl->url($track['cover_url'] ?? null);
            $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist);
        }
        unset($track);

        return $tracks;
    }

    private function prepareArtists(array $artists): array
    {
        foreach ($artists as &$artist) {
            $artist['_avatar'] = $this->mediaUrl->url($artist['avatar_url'] ?? null);
        }
        unset($artist);
        return $artists;
    }

    private function prepareAlbums(array $albums): array
    {
        $artistIds = array_unique(array_map(static fn ($a) => (int) $a['artist_id'], $albums));
        $artistsById = [];
        foreach ($artistIds as $id) {
            $artist = $this->artists->find($id);
            if ($artist) {
                $artistsById[$id] = $artist;
            }
        }

        foreach ($albums as &$album) {
            $album['_cover'] = $this->mediaUrl->url($album['cover_url'] ?? null);
            $album['_artist'] = $artistsById[(int) $album['artist_id']] ?? ['name' => 'Unknown Artist', 'slug' => ''];
        }
        unset($album);

        return $albums;
    }
}
