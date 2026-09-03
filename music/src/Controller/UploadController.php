<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\music\src\Services\AlbumService;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\UploadService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UploadController extends ControllerBase
{
    public function __construct(
        protected UploadService $uploads,
        protected ArtistService $artists,
        protected AlbumService $albums,
        protected CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.upload'),
            $container->get('music.artist'),
            $container->get('music.album'),
            $container->get('current_user'),
        );
    }

    /** GET /music/upload */
    public function index(Request $request, string $route_name, array $options): Response
    {
        $userId = (int) $this->currentUser->getUserId();
        $myArtists = $this->artists->forOwner($userId);

        if (empty($myArtists)) {
            return $this->renderTwig('@music/upload_create_artist.html.twig', [
                'errors' => [],
                'old'    => [],
                'meta'   => ['title' => 'Set Up Your Artist Profile — Music'],
            ]);
        }

        $preselectArtistId = (int) $request->query->get('artist_id', $myArtists[0]['id']);
        $albumsByArtist = [];
        foreach ($myArtists as $artist) {
            $albumsByArtist[(int) $artist['id']] = $this->albums->forArtist((int) $artist['id']);
        }

        return $this->renderTwig('@music/upload.html.twig', [
            'my_artists'          => $myArtists,
            'preselect_artist_id' => $preselectArtistId,
            'albums_by_artist'    => $albumsByArtist,
            'albums_by_artist_json' => htmlspecialchars(json_encode($albumsByArtist, JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES),
            'errors'              => [],
            'old'                 => [],
            'meta'                => ['title' => 'Upload — Music'],
        ]);
    }

    /** POST /music/upload/artist — first-time artist profile creation. */
    public function createArtist(Request $request, string $route_name, array $options): Response
    {
        $data = $request->request->all();
        unset($data['_csrf_token']);

        $name = trim((string) ($data['name'] ?? ''));
        $bio = trim((string) ($data['bio'] ?? '')) ?: null;

        if (mb_strlen($name) < 2) {
            return $this->renderTwig('@music/upload_create_artist.html.twig', [
                'errors' => ['name' => 'Artist/display name must be at least 2 characters.'],
                'old'    => $data,
                'meta'   => ['title' => 'Set Up Your Artist Profile — Music'],
            ], 422);
        }

        $userId = (int) $this->currentUser->getUserId();
        $user = $this->currentUser->getUser();
        $this->artists->create($userId, $user?->getDisplayName() ?? '', $name, $bio);

        return $this->redirect('/music/upload');
    }

    /** POST /music/upload */
    public function store(Request $request, string $route_name, array $options): Response
    {
        $data = $request->request->all();
        unset($data['_csrf_token']);

        $artistId = (int) ($data['artist_id'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));
        $albumTitle = trim((string) ($data['album_title'] ?? ''));
        $genre = trim((string) ($data['genre'] ?? '')) ?: null;
        $lyrics = trim((string) ($data['lyrics'] ?? '')) ?: null;
        $duration = (int) ($data['duration_seconds'] ?? 0);

        $userId = (int) $this->currentUser->getUserId();
        $user = $this->currentUser->getUser();
        $username = $user?->getDisplayName() ?? '';

        if (mb_strlen($title) < 1) {
            return $this->reRenderWithError($userId, $artistId, $data, ['title' => 'Track title is required.']);
        }

        $albumId = null;
        if ($albumTitle !== '') {
            $existing = $this->findAlbumByTitleForArtist($artistId, $albumTitle);
            $albumId = $existing ? (int) $existing['id'] : $this->albums->create($artistId, $albumTitle);
        }

        $audioFile = $request->files->get('audio_file');
        $coverFile = $request->files->get('cover_image');

        try {
            $trackId = $this->uploads->uploadTrack(
                $userId,
                $username,
                $artistId,
                $albumId,
                $title,
                $duration,
                $this->toRawFileArray($audioFile),
                $coverFile ? $this->toRawFileArray($coverFile) : null,
                $genre,
                $lyrics
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->reRenderWithError($userId, $artistId, $data, ['form' => $e->getMessage()]);
        }

        $artist = $this->artists->find($artistId);

        return $this->redirect('/music/artist/' . ($artist['slug'] ?? '') . '?uploaded=' . $trackId);
    }

    private function findAlbumByTitleForArtist(int $artistId, string $title): ?array
    {
        foreach ($this->albums->forArtist($artistId) as $album) {
            if (mb_strtolower($album['title']) === mb_strtolower($title)) {
                return $album;
            }
        }
        return null;
    }

    private function reRenderWithError(int $userId, int $artistId, array $old, array $errors): Response
    {
        $myArtists = $this->artists->forOwner($userId);
        $albumsByArtist = [];
        foreach ($myArtists as $artist) {
            $albumsByArtist[(int) $artist['id']] = $this->albums->forArtist((int) $artist['id']);
        }

        return $this->renderTwig('@music/upload.html.twig', [
            'my_artists'          => $myArtists,
            'preselect_artist_id' => $artistId,
            'albums_by_artist'    => $albumsByArtist,
            'albums_by_artist_json' => htmlspecialchars(json_encode($albumsByArtist, JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES),
            'errors'              => $errors,
            'old'                 => $old,
            'meta'                => ['title' => 'Upload — Music'],
        ], 422);
    }

    /**
     * Symfony's UploadedFile object isn't the raw $_FILES shape
     * FileSystemService::uploadFile() expects (confirmed signature takes
     * a plain array with name/tmp_name/size/error/type keys) — this
     * bridges the two rather than assuming they're interchangeable.
     */
    private function toRawFileArray(?\Symfony\Component\HttpFoundation\File\UploadedFile $file): array
    {
        if (!$file) {
            return [];
        }
        return [
            'name'     => $file->getClientOriginalName(),
            'type'     => $file->getClientMimeType(),
            'tmp_name' => $file->getPathname(),
            'error'    => $file->getError(),
            'size'     => $file->getSize(),
        ];
    }
}
