<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\FollowService;
use Simp\Pindrop\Modules\music\src\Services\LikeService;
use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;
use Simp\Pindrop\Modules\music\src\Services\PlaylistService;
use Simp\Pindrop\Modules\music\src\Services\TrackPresenterService;
use Simp\Pindrop\Modules\music\src\Services\TrackService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LibraryController extends ControllerBase
{
    public function __construct(
        protected LikeService $likes,
        protected FollowService $follows,
        protected PlaylistService $playlists,
        protected TrackService $tracks,
        protected ArtistService $artists,
        protected MediaUrlService $mediaUrl,
        protected TrackPresenterService $presenter,
        protected CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.like'),
            $container->get('music.follow'),
            $container->get('music.playlist'),
            $container->get('music.track'),
            $container->get('music.artist'),
            $container->get('music.media_url'),
            $container->get('music.track_presenter'),
            $container->get('current_user'),
        );
    }

    /** GET /music/library */
    public function index(Request $request, string $route_name, array $options): Response
    {
        $userId = (int) $this->currentUser->getUserId();

        $likedTrackIds = $this->likes->likedTrackIds($userId, 100);
        $likedTracks = $this->tracks->findMany($likedTrackIds);
        // findMany() doesn't guarantee order — re-sort to match the
        // most-recently-liked-first order likedTrackIds() already gives us.
        $likedTracks = $this->reorderByIdList($likedTracks, $likedTrackIds);
        $likedTracks = $this->enrichTracks($likedTracks);

        $myPlaylists = $this->playlists->forUser($userId);

        $followedArtistIds = $this->follows->followedArtistIds($userId, 100);
        $followedArtists = $this->artists->findMany($followedArtistIds);
        foreach ($followedArtists as &$artist) {
            $artist['_avatar'] = $this->mediaUrl->url($artist['avatar_url'] ?? null);
        }
        unset($artist);

        return $this->renderTwig('@music/library.html.twig', [
            'liked_tracks'      => $likedTracks,
            'playlists'         => $myPlaylists,
            'followed_artists'  => $followedArtists,
            'meta'              => ['title' => 'Your Library — Music'],
        ]);
    }

    private function enrichTracks(array $tracks): array
    {
        $artistCache = [];
        foreach ($tracks as &$track) {
            $artistId = (int) $track['artist_id'];
            if (!isset($artistCache[$artistId])) {
                $artistCache[$artistId] = $this->artists->find($artistId) ?? ['name' => 'Unknown Artist', 'slug' => ''];
            }
            $artist = $artistCache[$artistId];
            $track['_cover'] = $this->mediaUrl->url($track['cover_url'] ?? null);
            $track['_artist'] = $artist;
            $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist);
        }
        unset($track);
        return $tracks;
    }

    /** @param int[] $orderedIds */
    private function reorderByIdList(array $rows, array $orderedIds): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $ordered = [];
        foreach ($orderedIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }
}
