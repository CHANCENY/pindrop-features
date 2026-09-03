<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;
use Simp\Pindrop\Modules\music\src\Services\PlaylistService;
use Simp\Pindrop\Modules\music\src\Services\TrackPresenterService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PlaylistController extends ControllerBase
{
    public function __construct(
        protected PlaylistService $playlists,
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
            $container->get('music.playlist'),
            $container->get('music.artist'),
            $container->get('music.media_url'),
            $container->get('music.track_presenter'),
            $container->get('current_user'),
        );
    }

    /** POST /music/playlist/create */
    public function store(Request $request, string $route_name, array $options): Response
    {
        $data = $request->request->all();
        unset($data['_csrf_token']);

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return $this->redirect('/music/library?error=playlist_title_required');
        }

        $userId = (int) $this->currentUser->getUserId();
        $user = $this->currentUser->getUser();

        $id = $this->playlists->create(
            $userId,
            $user?->getDisplayName() ?? '',
            $title,
            trim((string) ($data['description'] ?? '')) ?: null,
            !empty($data['is_public'])
        );

        return $this->redirect('/music/playlist/' . $id);
    }

    /** GET /music/playlist/{id} */
    public function view(Request $request, string $route_name, array $options): Response
    {
        $id = (int) $request->query->get('id');
        $playlist = $this->playlists->find($id);

        if (!$playlist) {
            return $this->renderTwig('@music/404.html.twig', [], 404);
        }

        $userId = $this->currentUser->getUserId();
        $isOwner = $userId && (int) $playlist['user_id'] === (int) $userId;

        if (!$isOwner && (int) $playlist['is_public'] !== 1) {
            return $this->renderTwig('@music/403.html.twig', [], 403);
        }

        $tracks = $this->playlists->tracksFor($id);
        $artistCache = [];
        $playPayloads = [];
        foreach ($tracks as &$track) {
            $artistId = (int) $track['artist_id'];
            if (!isset($artistCache[$artistId])) {
                $artistCache[$artistId] = $this->artists->find($artistId) ?? ['name' => 'Unknown Artist', 'slug' => ''];
            }
            $artist = $artistCache[$artistId];
            $track['_cover'] = $this->mediaUrl->url($track['cover_url'] ?? null);
            $track['_artist'] = $artist;
            $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist);
            $playPayloads[] = $this->presenter->present($track, $artist);
        }
        unset($track);

        return $this->renderTwig('@music/playlist.html.twig', [
            'playlist'      => $playlist,
            'tracks'        => $tracks,
            'is_owner'      => $isOwner,
            'play_all_json' => htmlspecialchars(json_encode($playPayloads, JSON_UNESCAPED_SLASHES) ?: '[]', ENT_QUOTES),
            'meta' => [
                'title'       => $playlist['title'] . ' — Music',
                'description' => $playlist['description'] ?: ('A playlist with ' . count($tracks) . ' songs.'),
            ],
        ]);
    }

    /** POST /music/playlist/{id}/add-track — body: track_id */
    public function addTrack(Request $request, string $route_name, array $options): Response
    {
        $playlistId = (int) $request->query->get('id');
        [$playlist, $error] = $this->authorizeOwner($playlistId);
        if ($error) {
            return $error;
        }

        $trackId = (int) $request->request->get('track_id');
        if ($trackId > 0) {
            $this->playlists->addTrack($playlistId, $trackId);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true]);
        }
        return $this->redirect('/music/playlist/' . $playlistId);
    }

    /** POST /music/playlist/{id}/remove-entry — body: entry_id */
    public function removeEntry(Request $request, string $route_name, array $options): Response
    {
        $playlistId = (int) $request->query->get('id');
        [$playlist, $error] = $this->authorizeOwner($playlistId);
        if ($error) {
            return $error;
        }

        $entryId = (int) $request->request->get('entry_id');
        if ($entryId > 0) {
            $this->playlists->removeEntry($playlistId, $entryId);
        }

        return $this->redirect('/music/playlist/' . $playlistId);
    }

    /** POST /music/playlist/{id}/reorder — body: entry_ids[] (JSON array as a string field) */
    public function reorder(Request $request, string $route_name, array $options): Response
    {
        $playlistId = (int) $request->query->get('id');
        [$playlist, $error] = $this->authorizeOwner($playlistId, true);
        if ($error) {
            return $error;
        }

        $raw = (string) $request->request->get('entry_ids', '[]');
        try {
            $entryIds = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['success' => false, 'error' => 'Invalid payload'], 422);
        }

        if (is_array($entryIds)) {
            $this->playlists->reorder($playlistId, array_map('intval', $entryIds));
        }

        return $this->json(['success' => true]);
    }

    /** POST /music/playlist/{id}/toggle-public */
    public function togglePublic(Request $request, string $route_name, array $options): Response
    {
        $playlistId = (int) $request->query->get('id');
        [$playlist, $error] = $this->authorizeOwner($playlistId);
        if ($error) {
            return $error;
        }

        $isPublic = $this->playlists->togglePublic($playlistId);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'is_public' => $isPublic]);
        }
        return $this->redirect('/music/playlist/' . $playlistId);
    }

    /** POST /music/playlist/{id}/delete */
    public function delete(Request $request, string $route_name, array $options): Response
    {
        $playlistId = (int) $request->query->get('id');
        [$playlist, $error] = $this->authorizeOwner($playlistId);
        if ($error) {
            return $error;
        }

        $this->playlists->delete($playlistId);
        return $this->redirect('/music/library');
    }

    /**
     * @return array{0: ?array, 1: ?Response} [$playlist, $errorResponseOrNull]
     */
    private function authorizeOwner(int $playlistId, bool $jsonError = false): array
    {
        $playlist = $this->playlists->find($playlistId);
        $userId = $this->currentUser->getUserId();

        if (!$playlist) {
            $response = $jsonError
                ? $this->json(['success' => false, 'error' => 'Playlist not found'], 404)
                : $this->renderTwig('@music/404.html.twig', [], 404);
            return [null, $response];
        }

        if (!$userId || (int) $playlist['user_id'] !== (int) $userId) {
            $response = $jsonError
                ? $this->json(['success' => false, 'error' => 'Not your playlist'], 403)
                : $this->renderTwig('@music/403.html.twig', [], 403);
            return [null, $response];
        }

        return [$playlist, null];
    }
}
