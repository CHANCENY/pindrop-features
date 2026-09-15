<?php

namespace Simp\Pindrop\Modules\music\src\Routes;

use DI\Container;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Events\SystemEvents\Events;
use Simp\Pindrop\Logger\LoggerInterface;
use Simp\Pindrop\Modules\music\src\Services\AlbumService;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\FollowService;
use Simp\Pindrop\Modules\music\src\Services\LikeService;
use Simp\Pindrop\Modules\music\src\Services\ListeningHistoryService;
use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;
use Simp\Pindrop\Modules\music\src\Services\PlaylistService;
use Simp\Pindrop\Modules\music\src\Services\SearchService;
use Simp\Pindrop\Modules\music\src\Services\SeoService;
use Simp\Pindrop\Modules\music\src\Services\TrackPresenterService;
use Simp\Pindrop\Modules\music\src\Services\TrackService;
use Simp\Pindrop\Routing\AttributeRoute;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

class OpenMusicApiController extends ControllerBase
{


    public function __construct(
        protected MediaUrlService
        $mediaUrlService,
        protected DatabaseService $databaseService,
        protected LoggerInterface $loggerInterface,
        protected LikeService $likes,
        protected FollowService $follows,
        protected ArtistService $artists,
        protected CurrentUser $currentUser,
        protected PlaylistService $playlists,
        protected TrackPresenterService $presenter,
        protected AlbumService $albums,
        protected SearchService $search,
        protected TrackService $tracks,
        protected SeoService $seo,
        protected ListeningHistoryService $history

    ) {

    }

    public static function create(Container $container)
    {
        return new static(
            $container->get('music.media_url'),
            $container->get('database'),
            $container->get('logger'),
            $container->get('music.like'),
            $container->get('music.follow'),
            $container->get('music.artist'),
            $container->get('current_user'),
            $container->get('music.playlist'),
            $container->get('music.track_presenter'),
            $container->get('music.album'),
            $container->get('music.search'),
            $container->get('music.track'),
            $container->get('music.seo'),
            $container->get('music.history'),
        );
    }

    /**
     * @return array{0: ?array, 1: ?Response} [$playlist, $errorResponseOrNull]
     */
    private function authorizeOwner(int $playlistId): array
    {
        $playlist = $this->playlists->find($playlistId);
        $userId = $this->currentUser->getUserId();

        if (!$playlist) {
            $response = self::responseBuilder(message: "Playlist not found", status: false, httpCode: 404);
            return [null, $response];
        }

        if (!$userId || (int) $playlist['user_id'] !== (int) $userId) {
            $response = self::responseBuilder(message: "Playlist not found", status: false, httpCode: 403);
            return [null, $response];
        }

        return [$playlist, null];
    }

    private function enrichTracks(array $tracks): array
    {
        if (empty($tracks)) {
            return [];
        }

        $artistIds = array_unique(array_map(static fn($t) => (int) $t['artist_id'], $tracks));
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
            $track['_cover'] = $this->mediaUrlService->url($track['cover_url'] ?? null);
            $track['audio_uri'] = $this->mediaUrlService->url($track['audio_uri'] ?? null);
            $track['cover_url'] = $this->mediaUrlService->url($track['cover_url'] ?? null);
        }
        unset($track);

        return $tracks;
    }

    #[AttributeRoute('/api/session', ['GET', 'POST'], permission: [])]
    public function getSession(Request $request, string $route_name, array $options = [])
    {

        /**
         * @var CurrentUser|null
         */
        $currentUser = getAppContainer()->get('current_user');
        if ($currentUser instanceof CurrentUser) {
            return self::responseBuilder(['session' => $currentUser->getSessionId()], message: "You are logged in");
        }

        return self::responseBuilder(message: "You need to login first or again");

    }

    #[AttributeRoute('/api/session/create', ['POST'], permission: [])]
    public function loginSession(Request $request, string $route_name, array $options)
    {
        // Check if is post method request in that case we are signing user.
        if ($request->isMethod(Request::METHOD_POST)) {
            $email = $request->request->get('email');
            $password = $request->request->get('password');

            if (!$email || !$password)
                return self::responseBuilder(
                    status: false,
                    message: "Email and password both need to be submitted"
                );

            $response = CurrentUser::normalLoginUser($request, $email, $password);

            if ($response instanceof RedirectResponse) {

                /**
                 * @var CurrentUser|null
                 */
                $currentUser = getAppContainer()->get('current_user');
                if ($currentUser instanceof CurrentUser) {
                    return self::responseBuilder([
                        'session' => $currentUser->getSessionId(),
                        'cookie' => "PHPSESSID=" . $currentUser->getSessionId() . ";session_id=" . $currentUser->getSessionId()
                    ], httpCode: 302, message: "Logged in successfully");
                }

                return self::responseBuilder(message: "Failed to login", status: false);
            }

            return self::responseBuilder(message: $response);
        }
    }

    #[AttributeRoute('/api/session/remove', ['GET'], permission: [])]
    public function logoutSession(Request $request, string $route_name, array $options)
    {
        $sessionId = session_id();

        if ($sessionId) {
            $session = CurrentUser::findBySessionId($this->databaseService, $this->loggerInterface, $sessionId);

            if ($session) {
                $session->delete();
                getAppContainer()->get('logger')->info('User logged out', [
                    'user_id' => $session->getUserId(),
                    'session_id' => $sessionId
                ]);
                appEvents()->invokeEvents(Events::AUTH_LOGOUT, ['user_id' => $session->getUserId()]);
                return self::responseBuilder(message: "Logout successfully");
            }
        }

        return self::responseBuilder(message: "No active session found", status: false);

    }

    #[AttributeRoute('/api/account/create', ['POST'], permission: [])]
    public function createAccount(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod(Request::METHOD_POST))
        {
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $fullname = $request->request->get('full_name');

            if (empty($email) || empty($password))
            {
                return self::responseBuilder(message: "Email or Password is required");
            }

            $user = User::loadByEmail($email, $this->databaseService);

            if ($user instanceof User) return self::responseBuilder(message: "Sorry this email already has account");

            $username = explode("@", $email)[0] ?? "m-".time();
            $list = explode(' ', $fullname);

            $user = new User([
                'email'   => $email,
                'password' => $password,
                'username' => $username,
                'role'     => 'user',
                'full_name'  => $fullname ?? "No name",
                'first_name' => $list[0] ?? "",
                'last_name'  => $list[1] ?? "",
                'status'     => 'active'

            ], $this->databaseService, $this->loggerInterface);

            $user->setPassword($password);

            $bool = $user->save();

            return self::responseBuilder(message: "Account created", status: $bool);
        }
    }

    public static function responseBuilder(mixed $data = "", bool $status = true, int $httpCode = 200, string $contentType = 'application/json', ?string $message = null)
    {
        $response = new Response(headers: [
            'Content-Type' => $contentType
        ]);
        $response->setStatusCode($httpCode);

        $pro_data = null;
        if ($contentType === 'application/json') {
            $pro_data = json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
        }

        $response->setContent($pro_data);

        return $response;

    }

    #[AttributeRoute('/api/current-user', ['GET'], permission: [])]
    public function getCurrentUser(Request $request, string $route_name, array $options = [])
    {
        /**
         * @var CurrentUser|null
         */
        $currentUser = getAppContainer()->get('current_user');
        if ($currentUser instanceof CurrentUser) {
            $user = $currentUser->getUser();

            return self::responseBuilder([
                'id' => $user->getId(),
                'displayName' => $user->getDisplayName(),
                'username' => $user->getUsername(),
                'avatar' => $user->getAvatarUrl(),
            ], message: "You are logged in");
        }

        return self::responseBuilder(message: "You need to login first or again", status: false);
    }

    #[AttributeRoute('/api/artists', ['GET'], permission: [])]
    public function getArtists(Request $request, string $route_name, array $options = [])
    {
        $limit = $request->query->get('limit', 1000);
        $offset = $request->query->get('page', 0);
        /**
         * @var ArtistService
         */
        $artistManager = getAppContainer()->get('music.artist');

        $all_artists = $artistManager->getAllArtists($limit, $offset);
        $pro_data = array_map(function ($artist) {
            return [
                'id' => $artist['id'],
                'name' => $artist['name'],
                'slug' => $artist['slug'],
                'bio' => $artist['bio'],
                'avatarUrl' => $this->mediaUrlService->url($artist['avatar_url'] ?? null),
                'verified' => $artist['verified'],
                'followerCount' => $artist['follower_count'],
                'tracksCount' => $artist['tracks_count'],
                'ownerUserId' => $artist['owner_user_id'],


            ];
        }, $all_artists);

        return self::responseBuilder($pro_data, message: "All artists fetched successfully");

    }

    #[AttributeRoute('/api/albums', ['GET'], permission: [])]
    public function getAlbums(Request $request, string $route_name, array $options)
    {
        $limit = $request->query->get('limit', 1000);
        $offset = $request->query->get('page', 0);
        /**
         * @var AlbumService
         */
        $albumService = getAppContainer()->get('music.album');
        $all_albums = $albumService->getAllAlbums($limit, $offset);
        $pro_albums = array_map(function ($album) {
            return [
                'id' => $album['id'],
                'artistId' => $album['artist_id'],
                'slug' => $album['slug'],
                'title' => $album['title'],
                'coverUrl' => $this->mediaUrlService->url($album['cover_url']),
                'releaseDate' => $album['release_date'],
                'type' => $album['type'],
                'tracksCount' => $album['tracks_count'],
                'status' => $album['status']
            ];
        }, $all_albums);

        return self::responseBuilder($pro_albums, message: "All albums fetched successfully");
    }

    #[AttributeRoute('/api/tracks', ['GET'], permission: [])]
    public function getTracks(Request $request, string $route_name, array $options)
    {
        $limit = $request->query->get('limit', 1000);
        $offset = $request->query->get('page', 1);
        $artist = $request->query->get('artist');
        $album = $request->query->get('album');
        $term = $request->query->get('term');

        /**
         * @var TrackService
         * 
         */
        $trackService = getAppContainer()->get('music.track');

        $tracks = [];
        if (!empty($album)) {
            $tracks = $trackService->forAlbum($album);
        } elseif (!empty($artist)) {
            $tracks = $trackService->forArtist($artist, $offset, $limit);
        } elseif (!empty($term)) {
            $tracks = $trackService->search($term, $limit);
        } else {
            $tracks = $trackService->trending($limit);
        }


        $pro_tracks = array_map(function ($track) {
            return [
                'id' => $track['id'],
                'albumId' => $track['album_id'],
                'artistId' => $track['artist_id'],
                'title' => $track['title'],
                'slug' => $track['slug'],
                'audioUri' => $this->mediaUrlService->url($track['audio_uri']),
                'coverUrl' => $this->mediaUrlService->url($track['cover_url']),
                'durationSeconds' => $track['duration_seconds'],
                'trackNumber' => $track['track_number'],
                'genre' => $track['genre'],
                'lyrics' => $track['lyrics'],
                'playsCount' => $track['plays_count'],
                'likesCount' => $track['likes_count'],
                'status' => $track['status']
            ];
        }, $tracks);

        return self::responseBuilder($pro_tracks, message: "Tracks fetched");
    }

    #[AttributeRoute('/api/playlists', ['GET'], permission: [])]
    public function getPlaylists(Request $request, string $route_name, array $options)
    {
        $limit = $request->query->get('limit', 1000);
        $offset = $request->query->get('page', 0);

        /**
         * @var PlaylistService
         */
        $playlistService = getAppContainer()->get('music.playlist');

        $list = $playlistService->getAllPlaylists($limit, $offset);

        $pro_data = array_map(function ($item) use ($playlistService) {
            return [
                'id' => $item['id'],
                'title' => $item['title'],
                'slug' => $item['slug'],
                'authorUsername' => $item['author_username'],
                'description' => $item['description'],
                'isPublic' => $item['is_public'],
                'tracksCount' => $item['tracks_count'],
                'coverUrl' => $this->mediaUrlService->url($item['cover_url']),
                'tracks' => array_map(function ($item_track) use ($item) {

                    return [
                        'id' => $item_track['entry_id'],
                        'playlistId' => $item['id'],
                        'trackId' => $item_track['id'],
                        'position' => $item_track['position']
                    ];
                }, $playlistService->tracksFor($item['id']))
            ];
        }, $list);

        return self::responseBuilder($pro_data, message: 'Playlists fetched');

    }

    #[AttributeRoute('/api/music/interations/[type:string]/[id:int]', ['GET'], permission: [])]
    public function getLikes(Request $request, string $route_name, array $options)
    {
        $id = (int) $request->query->get('id');
        $likeType = $request->query->get('type');
        $userId = (int) $this->currentUser->getUserId();
        $likes = $this->likes->isLiked($userId, $likeType, $id);

        return self::responseBuilder(['is_liked' => $likes], message: "Fetched interations");

    }

    #[AttributeRoute('/api/music/fellow/artist/[id:int]', ['GET'], permission: [])]
    public function getFollow(Request $request, string $route_name, array $options)
    {
        $id = (int) $request->query->get('id');

        $userId = (int) $this->currentUser->getUserId();
        $likes = $this->follows->isFollowing($userId, $id);
        return self::responseBuilder(['is_following' => $likes], message: "Fetched interations");

    }

    #[AttributeRoute('/api/music/history/tracks', ['GET'], permission: [])]
    public function getHistory(Request $request, string $route_name, array $options)
    {
        $userId = (int) $this->currentUser->getUserId();
        $limit = (int) $request->query->get('limit', 20);
        $likes = $this->history->recentTrackIdsForUser($userId, $limit);

        return self::responseBuilder($likes, message: "Fetched recent played tracks");

    }

    #[AttributeRoute('/api/music/random-pick', ['GET'], permission: [])]
    public function getRandom(Request $request, string $route_name, array $options)
    {
        $userId = (int) $this->currentUser->getUserId();
        $limit = (int) $request->query->get('limit', 20);
        $likes = $this->tracks->randomPick($limit);

        $likes = $this->enrichTracks($likes);

        return self::responseBuilder($likes, message: "Fetched random picked tracks");

    }

    #[AttributeRoute('/api/music/most-played', ['GET'], permission: [])]
    public function getMostPlayed(Request $request, string $route_name, array $options)
    {
        $userId = (int) $this->currentUser->getUserId();
        $limit = (int) $request->query->get('limit', 20);
        $likes = $this->history->mostPlayed($userId, $limit);

        $likes = $this->enrichTracks($likes);

        return self::responseBuilder($likes, message: "Fetched mosted played tracks");

    }

    #[AttributeRoute('/api/music/recent-played', ['GET'], permission: [])]
    public function getRecentPlayed(Request $request, string $route_name, array $options)
    {
        $userId = (int) $this->currentUser->getUserId();
        $limit = (int) $request->query->get('limit', 20);
        $likes = $this->history->recentTrackIdsForUser($userId, $limit);

        $likes = $this->tracks->findMany($likes);
        $likes = $this->enrichTracks($likes);

        return self::responseBuilder($likes, message: "Fetched recent played tracks");

    }

    #[AttributeRoute('/api/music/like/[type:string]/[id:int]', ['GET'], permission: [])]
    public function toggleLike(Request $request, string $route_name, array $options): Response
    {
        $type = (string) $request->query->get('type');
        $id = (int) $request->query->get('id');
        $userId = (int) $this->currentUser->getUserId();

        try {
            $liked = $this->likes->toggle($userId, $type, $id);
        } catch (\InvalidArgumentException $e) {
            return self::responseBuilder(message: $e->getMessage(), status: false, httpCode: 422);
        }

        return self::responseBuilder([
            'liked' => $liked,
            'like_count' => $this->likes->countFor($type, $id),
        ], message: "Like is done");
    }

    #[AttributeRoute('/api/music/report', ['GET', 'POST'], permission: [])]
    public function report(Request $request, string $route_name, array $options): Response
    {
        $data = $request->request->all();
        unset($data['_csrf_token']);

        $type = (string) ($data['reportable_type'] ?? '');
        $id = (int) ($data['reportable_id'] ?? 0);
        $reason = trim((string) ($data['reason'] ?? ''));

        if (!in_array($type, ['track', 'album', 'artist', 'playlist'], true) || $id <= 0 || $reason === '') {
            return self::responseBuilder(message: "Invalid report: report", status: false, httpCode: 422);
        }

        $reporterId = (int) $this->currentUser->getUserId();

        $this->databaseService->table('music_reports')->insert([
            'reporter_id' => $reporterId,
            'reportable_type' => $type,
            'reportable_id' => $id,
            'reason' => $reason,
        ]);

        return self::responseBuilder(message: "Reporting done");
    }

    #[AttributeRoute('/api/music/follow/[id:int]', ['GET'], permission: [])]
    public function toggleFollow(Request $request, string $route_name, array $options): Response
    {
        $artistId = (int) $request->query->get('id');
        $artist = $this->artists->find($artistId);

        if (!$artist) {
            return self::responseBuilder(message: 'Artist not found', status: false, httpCode: 404);
        }

        $userId = (int) $this->currentUser->getUserId();
        $following = $this->follows->toggle($userId, $artistId);
        $this->artists->adjustFollowerCount($artistId, $following ? 1 : -1);

        return self::responseBuilder(['following' => $following], message: "Following done");
    }

    #[AttributeRoute('/api/music/made-for-you', ['GET'], permission: [])]
    public function trending(Request $request, string $route_name, array $options): Response
    {
        $limit = $request->query->get('limit', 12);
        $madeForYou = $this->buildMadeForYou();
        return self::responseBuilder($madeForYou, message: "Fetched made for you songs");
    }

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

    #[AttributeRoute('/api/music/trending', ['GET'], permission: [])]
    public function madeForYou(Request $request, string $route_name, array $options): Response
    {
        $limit = $request->query->get('limit', 12);
        $trending = $this->enrichTracks($this->tracks->trending($limit));
        return self::responseBuilder($trending, message: "Fetched trending songs");
    }

    #[AttributeRoute('/api/music/others', ['GET'], permission: [])]
    public function others(Request $request, string $route_name, array $options): Response
    {

        $recent = $this->enrichTracks($this->tracks->recentlyAdded(12));
        $popularArtists = $this->prepareArtists($this->artists->popular(10));
        $recentAlbums = $this->prepareAlbums($this->albums->recent(10));

        return self::responseBuilder([

            'recently_added' => $recent,
            'popular_artists' => $popularArtists,
            'recent_albums' => $recentAlbums,


        ], message: "Fetched");
    }

    private function prepareArtists(array $artists): array
    {
        foreach ($artists as &$artist) {
            $artist['_avatar'] = $this->mediaUrlService->url($artist['avatar_url'] ?? null);
        }
        unset($artist);
        return $artists;
    }

    private function prepareAlbums(array $albums): array
    {
        $artistIds = array_unique(array_map(static fn($a) => (int) $a['artist_id'], $albums));
        $artistsById = [];
        foreach ($artistIds as $id) {
            $artist = $this->artists->find($id);
            if ($artist) {
                $artistsById[$id] = $artist;
            }
        }

        foreach ($albums as &$album) {
            $album['_cover'] = $this->mediaUrlService->url($album['cover_url'] ?? null);
            $album['_artist'] = $artistsById[(int) $album['artist_id']] ?? ['name' => 'Unknown Artist', 'slug' => ''];
        }
        unset($album);

        return $albums;
    }

    #[AttributeRoute('/api/music/playlist/create', ['POST'], permission: [])]
    public function store(Request $request, string $route_name, array $options): Response
    {
        $data = $request->request->all();
        unset($data['_csrf_token']);

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return self::responseBuilder(message: "Title is required", status: false, httpCode: 422);
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

        return self::responseBuilder(['id' => $id], message: "Playlist created");
    }

    #[AttributeRoute('/api/music/playlist/[id:int]', ['GET'], permission: [])]
    public function viewPlaylist(Request $request, string $route_name, array $options): Response
    {
        $id = (int) $request->query->get('id');
        $playlist = $this->playlists->find($id);

        if (!$playlist) {
            return self::responseBuilder(message: "Not found", httpCode: 404, status: false);
        }

        $userId = $this->currentUser->getUserId();
        $isOwner = $userId && (int) $playlist['user_id'] === (int) $userId;

        if (!$isOwner && (int) $playlist['is_public'] !== 1) {
            return self::responseBuilder(message: "Not allowed", status: false, httpCode: 403);
        }

        $tracks = $this->playlists->tracksFor($id);
        $artistCache = [];
        $playPayloads = [];
        foreach ($tracks as &$track) {
            $artistId = (int) $track['artist_id'];
            if (!isset($artistCache[$artistId])) {
                $artistCache[$artistId] = $this->artists->find($artistId) ?? ['name' => 'Unknown Artist', 'slug' => ''];
            }
            if (empty($track['cover_url'])) {
                $album = $this->albums->find((int) $track['album_id']);
                $track['cover_url'] = $album['cover_url'] ?? null;
            }
            $artist = $artistCache[$artistId];
            $track['_cover'] = $this->mediaUrlService->url($track['cover_url'] ?? null);
            $track['_artist'] = $artist;
            $playPayloads[] = $this->presenter->present($track, $artist);
        }
        unset($track);

        return self::responseBuilder([
            'playlist' => $playlist,
            'tracks' => $tracks,
            'is_owner' => $isOwner,
            'play_all_json' => $playPayloads,
            'meta' => [
                'title' => $playlist['title'] . ' — Music',
                'description' => $playlist['description'] ?: ('A playlist with ' . count($tracks) . ' songs.'),
            ],
        ]);

    }

    #[AttributeRoute('/api/music/playlist/[id:int]/add-track', ['POST'], permission: [])]
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

        return self::responseBuilder(message: "Adding track is done");
    }

    #[AttributeRoute('/api/music/playlist/[id:int]/remove-entry', ['POST'], permission: [])]
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

        return self::responseBuilder(message: "Removing track done");
    }

    #[AttributeRoute('/api/music/playlist/[id:int]/reorder', ['POST'], permission: [])]
    public function reorder(Request $request, string $route_name, array $options): Response
    {
        $playlistId = (int) $request->query->get('id');
        [$playlist, $error] = $this->authorizeOwner($playlistId);
        if ($error) {
            return $error;
        }

        $raw = (string) $request->request->get('entry_ids', '[]');
        try {
            $entryIds = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::responseBuilder(message: "Invalid payload", status: false, httpCode: 422);
        }

        if (is_array($entryIds)) {
            $this->playlists->reorder($playlistId, array_map('intval', $entryIds));
        }

        return self::responseBuilder(message: "Reordering is done");
    }

    #[AttributeRoute('/api/music/playlist/[id:int]/toggle-public', ['POST'], permission: [])]
    public function togglePublic(Request $request, string $route_name, array $options): Response
    {
        $playlistId = (int) $request->query->get('id');
        [$playlist, $error] = $this->authorizeOwner($playlistId);
        if ($error) {
            return $error;
        }

        $isPublic = $this->playlists->togglePublic($playlistId);
        return self::responseBuilder(['is_public' => $isPublic], message: "Toggling done");
    }

    #[AttributeRoute('/api/music/playlist/[id:int]/delete', ['DELETE'], permission: [])]
    public function delete(Request $request, string $route_name, array $options): Response
    {
        $playlistId = (int) $request->query->get('id');
        [$playlist, $error] = $this->authorizeOwner($playlistId);
        if ($error) {
            return $error;
        }

        $this->playlists->delete($playlistId);
        return self::responseBuilder(message: "done the deletion");
    }

    #[AttributeRoute('/api/music/search', ['GET'], permission: [])]
    public function index(Request $request, string $route_name, array $options): Response
    {
        $term = (string) $request->query->get('q', '');
        $results = $this->search->search($term, 15);

        $artistsById = [];
        foreach ($results['tracks'] as $t) {
            $id = (int) $t['artist_id'];
            if (!isset($artistsById[$id])) {
                $artistsById[$id] = $this->artists->find($id) ?? ['name' => 'Unknown Artist', 'slug' => ''];
            }
        }

        foreach ($results['tracks'] as &$track) {
            $artist = $artistsById[(int) $track['artist_id']];
            if (empty($track['cover_url'])) {
                $album = $this->albums->find((int) $track['album_id']);
                $track['cover_url'] = $album['cover_url'] ?? null;
            }
            $track['_cover'] = $this->mediaUrlService->url($track['cover_url'] ?? null);
            $track['_artist'] = $artist;
            $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist);
        }
        unset($track);

        foreach ($results['artists'] as &$artist) {
            $artist['_avatar'] = $this->mediaUrlService->url($artist['avatar_url'] ?? null);
        }
        unset($artist);

        foreach ($results['albums'] as &$album) {
            $album['_cover'] = $this->mediaUrlService->url($album['cover_url'] ?? null);
            $album['_artist'] = $artistsById[(int) $album['artist_id']]
                ?? $this->artists->find((int) $album['artist_id'])
                ?? ['name' => 'Unknown Artist', 'slug' => ''];
        }
        unset($album);

        return self::responseBuilder([
            'term' => $term,
            'results' => $results,
            'meta' => [
                'title' => $term !== '' ? "Search: {$term} — Music" : 'Search — Music',
                'description' => 'Search songs, albums, and artists.',
            ],
        ]);
    }

    #[AttributeRoute('/api/music/artist/[slug:string]', ['GET'], permission: [])]
    public function viewArtist(Request $request, string $route_name, array $options): Response
    {
        $slug = (string) $request->query->get('slug');
        $artist = $this->artists->findBySlug($slug);

        if (!$artist) {
            return self::responseBuilder(message: "Not found", status: false, httpCode: 404);
        }

        $topTracks = $this->tracks->topForArtist((int) $artist['id'], 10);
        foreach ($topTracks as &$track) {
            if (empty($track['cover_url'])) {
                $album = $this->albums->find((int) $track['album_id']);
                $track['cover_url'] = $album['cover_url'] ?? null;
            }
            $track['_cover'] = $this->mediaUrlService->url($track['cover_url'] ?? null) ?? $this->mediaUrlService->url($artist['avatar_url'] ?? null);
            $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist);
        }
        unset($track);

        $albums = $this->albums->forArtist((int) $artist['id']);
        foreach ($albums as &$album) {
            $album['_cover'] = $this->mediaUrlService->url($album['cover_url'] ?? null);
        }
        unset($album);

        $userId = $this->currentUser->getUserId();

        return self::responseBuilder([
            'artist' => $artist,
            'avatar' => $this->mediaUrlService->url($artist['avatar_url'] ?? null),
            'top_tracks' => $topTracks,
            'albums' => $albums,
            'is_owner' => $userId && (int) $artist['owner_user_id'] === (int) $userId,
            'is_following' => $userId ? $this->follows->isFollowing((int) $userId, (int) $artist['id']) : false,
            'seo_meta' => $this->seo->artistMetaTags($artist, $request->getSchemeAndHttpHost()),
            'json_ld_script' => $this->seo->jsonLdScriptTag($this->seo->artistJsonLd($artist, $request->getSchemeAndHttpHost())),
            'meta' => [
                'title' => $artist['name'] . ' — Music',
                'description' => $artist['bio'] ?: ('Listen to ' . $artist['name'] . ' on Music.'),
            ],
        ]);
    }

    #[AttributeRoute('/api/music/artist/[artistSlug:string]/album/[albumSlug:string]', ['GET'], permission: [])]
    public function view(Request $request, string $route_name, array $options): Response
    {
        $artistSlug = (string) $request->query->get('artistSlug');
        $albumSlug = (string) $request->query->get('albumSlug');

        $artist = $this->artists->findBySlug($artistSlug);
        if (!$artist) {
            return self::responseBuilder(message: "Not found", status: false, httpCode: 404);
        }

        $album = $this->albums->findByArtistAndSlug((int) $artist['id'], $albumSlug);
        if (!$album) {
            return self::responseBuilder(message: "Not found", status: false, httpCode: 404);
        }

        $tracks = $this->tracks->forAlbum((int) $album['id']);
        $playPayloads = [];
        foreach ($tracks as &$track) {
            if (empty($track['cover_url'])) {
                $album = $this->albums->find((int) $track['album_id']);
                $track['cover_url'] = $album['cover_url'] ?? null;
            }
            $track['_cover'] = $this->mediaUrlService->url($track['cover_url'] ?? null) ?? $this->mediaUrlService->url($album['cover_url'] ?? null);
            $playPayloads[] = $track;
        }
        unset($track);

        $totalSeconds = array_sum(array_column($tracks, 'duration_seconds'));

        return self::responseBuilder([
            'artist' => $artist,
            'album' => $album,
            'cover' => $this->mediaUrlService->url($album['cover_url'] ?? null),
            'tracks' => $tracks,
            'total_duration' => (int) $totalSeconds,
            'seo_meta' => $this->seo->albumMetaTags($album, $artist, $request->getSchemeAndHttpHost()),
            'json_ld_script' => $this->seo->jsonLdScriptTag(
                $this->seo->albumJsonLd($album, $artist, $tracks, $request->getSchemeAndHttpHost())
            ),
            'meta' => [
                'title' => $album['title'] . ' by ' . $artist['name'] . ' — Music',
                'description' => 'Listen to ' . $album['title'] . ' by ' . $artist['name'] . '.',
            ],
        ]);
    }

    #[AttributeRoute('/api/music/artist/[artistSlug:string]/track/[trackSlug:string]', ['GET'], permission: [])]
    public function viewTrack(Request $request, string $route_name, array $options): Response
    {
        $artistSlug = (string) $request->query->get('artistSlug');
        $trackSlug = (string) $request->query->get('trackSlug');

        $artist = $this->artists->findBySlug($artistSlug);
        if (!$artist) {
            return self::responseBuilder(message: "Not found", status: false, httpCode: 404);
        }

        $track = $this->tracks->findByArtistAndSlug((int) $artist['id'], $trackSlug);
        if (!$track) {
            return self::responseBuilder(message: "Not found", status: false, httpCode: 404);
        }

        $userId = $this->currentUser->getUserId();
        $isLiked = $userId ? $this->likes->isLiked((int) $userId, 'track', (int) $track['id']) : false;

        if (empty($track['cover_url'])) {
            $album = $this->albums->find((int) $track['album_id']);
            $track['cover_url'] = $album['cover_url'] ?? null;
        }

        $track['_cover'] = $this->mediaUrlService->url($track['cover_url'] ?? null) ?? $this->mediaUrlService->url($artist['avatar_url'] ?? null);
        $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist, $isLiked);
        $track['_liked'] = $isLiked;

        $related = $this->tracks->related((int) $track['id'], $track['genre'] ?? null, (int) $artist['id'], 8);
        $relatedIds = array_map(static fn($r) => (int) $r['id'], $related);
        $relatedLiked = $userId ? $this->likes->likedMap((int) $userId, 'track', $relatedIds) : [];

        foreach ($related as &$r) {
            $rArtist = (int) $r['artist_id'] === (int) $artist['id']
                ? $artist
                : ($this->artists->find((int) $r['artist_id']) ?? ['name' => 'Unknown Artist', 'slug' => '']);
            if (empty($r['cover_url'])) {
                $album = $this->albums->find((int) $r['album_id']);
                $r['cover_url'] = $album['cover_url'] ?? null;
            }
            $r['_cover'] = $this->mediaUrlService->url($r['cover_url'] ?? null);
            $r['_artist'] = $rArtist;
            $r['_play_json'] = $this->presenter->presentAsAttribute($r, $rArtist, $relatedLiked[(int) $r['id']] ?? false);
        }
        unset($r);

        return self::responseBuilder([
            'artist' => $artist,
            'track' => $track,
            'related' => $related,
            'my_playlists' => $userId ? $this->playlists->forUser((int) $userId) : [],
            'seo_meta' => $this->seo->trackMetaTags($track, $artist, $request->getSchemeAndHttpHost()),
            'json_ld_script' => $this->seo->jsonLdScriptTag(
                $this->seo->trackJsonLd($track, $artist, null, $request->getSchemeAndHttpHost())
            ),
            'meta' => [
                'title' => $track['title'] . ' — ' . $artist['name'],
                'description' => $track['title'] . ' by ' . $artist['name'] . ' — listen now.',
            ],
        ]);
    }

    #[AttributeRoute('/api/music/track/[id:int]/play-beacon', ['POST'], permission: [])]
    public function recordPlay(Request $request, string $route_name, array $options): Response
    {
        $trackId = (int) $request->query->get('id');
        $track = $this->tracks->find($trackId);

        if (!$track) {
            return self::responseBuilder(message: "Not found", status: false, httpCode: 404);
        }

        $this->tracks->incrementPlaysCount($trackId);

        $userId = $this->currentUser->getUserId();
        if ($userId) {
            $this->history->record((int) $userId, $trackId);
        }

        return self::responseBuilder(message: "done");
    }

    #[AttributeRoute('/api/music/related', ['GET'], permission: [])]
    public function related(Request $request, string $route_name, array $options): Response
    {
        $artist_id = $request->query->get('artist');
        $genre     = $request->query->get('genre');
        $track_id  = $request->query->get('track');
        $limit     = $request->query->get('limit', 10);

        $tracks = $this->tracks->related($track_id, $genre, $artist_id, $limit);
        $tracks = $this->enrichTracks($tracks);
        return self::responseBuilder($tracks, message: "Fetched the related");
    }

    #[AttributeRoute('/api/music/track/[id:int]', ['GET'], permission: [])]
    public function viewTrackById(Request $request, string $route_name, array $options): Response
    {
        $trackId = (int) $request->query->get('id');

        $track = $this->tracks->find($trackId);
        if (!$track) {
            return self::responseBuilder(message: "Not found", status: false, httpCode: 404);
        }

        
        

        $artist = $this->artists->find((int) $track['artist_id']);
        if (!$artist) {
            return self::responseBuilder(message: "Not found", status: false, httpCode: 404);
        }

        $track['cover_url'] = $this->mediaUrlService->url($track['cover_url'] ?? null) 
        ?? $this->mediaUrlService->url($artist['avatar_url'] ?? null);

        $track['audio_uri'] = $this->mediaUrlService->url($track['audio_uri'] ?? null);

        $artist['_avatar'] = $this->mediaUrlService->url($artist['avatar_url'] ?? null);

        return self::responseBuilder([
            'artist' => $artist,
            'track' => $track,
        ]);
    }

}
