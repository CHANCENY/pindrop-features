<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

use Simp\Pindrop\Database\DatabaseService;

class TrackService
{
    private const TABLE = 'music_tracks';

    public function __construct(protected DatabaseService $database) {}

    public function find(int $id): ?array
    {
        return $this->database->table(self::TABLE)
            ->where('id', '=', $id)
            ->where('status', '!=', 'removed')
            ->first();
    }

    /** Track slugs are unique per-artist (mirrors AlbumService's convention). */
    public function findByArtistAndSlug(int $artistId, string $slug): ?array
    {
        return $this->database->table(self::TABLE)
            ->where('artist_id', '=', $artistId)
            ->where('slug', '=', $slug)
            ->where('status', '!=', 'removed')
            ->first();
    }

    public function forAlbum(int $albumId): array
    {
        return $this->database->table(self::TABLE)
            ->where('album_id', '=', $albumId)
            ->where('status', '=', 'published')
            ->orderBy('track_number', 'ASC')
            ->get();
    }

    public function forArtist(int $artistId, int $page = 1, int $perPage = 30): array
    {
        return $this->database->table(self::TABLE)
            ->where('artist_id', '=', $artistId)
            ->where('status', '=', 'published')
            ->latest('created_at')
            ->forPage($page, $perPage)
            ->get();
    }

    public function topForArtist(int $artistId, int $limit = 5): array
    {
        return $this->database->table(self::TABLE)
            ->where('artist_id', '=', $artistId)
            ->where('status', '=', 'published')
            ->orderBy('plays_count', 'DESC')
            ->limit($limit)
            ->get();
    }

    /** Same-genre or same-artist suggestions — the rule-based "recommendation" basis for the home feed / up-next queue. */
    public function related(int $trackId, ?string $genre, int $artistId, int $limit = 10): array
    {
        $query = $this->database->table(self::TABLE)
            ->where('id', '!=', $trackId)
            ->where('status', '=', 'published');

        if ($genre) {
            $query->where('artist_id', '!=', $artistId)->where('genre', '=', $genre);
        } else {
            $query->where('artist_id', '=', $artistId);
        }

        $results = $query->orderBy('plays_count', 'DESC')->limit($limit)->get();

        // Fall back to same-artist tracks if a genre search came up short.
        if ($genre && count($results) < $limit) {
            $more = $this->database->table(self::TABLE)
                ->where('id', '!=', $trackId)
                ->where('artist_id', '=', $artistId)
                ->where('status', '=', 'published')
                ->orderBy('plays_count', 'DESC')
                ->limit($limit - count($results))
                ->get();
            $results = array_merge($results, $more);
        }

        return $results;
    }

    public function trending(int $limit = 20): array
    {
        return $this->database->table(self::TABLE)
            ->where('status', '=', 'published')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->orderBy('plays_count', 'DESC')
            ->limit($limit)
            ->get();
    }

    public function recentlyAdded(int $limit = 20): array
    {
        return $this->database->table(self::TABLE)
            ->where('status', '=', 'published')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        return $this->database->table(self::TABLE)
            ->where('status', '=', 'published')
            ->whereRaw('MATCH(title) AGAINST (? IN NATURAL LANGUAGE MODE)', [$term])
            ->limit($limit)
            ->get();
    }

    /**
     * @param string $uploaderUsername denormalized snapshot from the
     *   uploader's own session data — see ArtistService::create() docblock.
     */
    public function create(
        int $artistId,
        ?int $albumId,
        string $title,
        string $audioUri,
        int $durationSeconds,
        int $uploaderId,
        string $uploaderUsername,
        ?string $coverUrl = null,
        ?string $genre = null,
        ?string $lyrics = null,
        ?int $trackNumber = null
    ): int {
        return $this->database->table(self::TABLE)->insert([
            'artist_id'             => $artistId,
            'album_id'              => $albumId,
            'title'                 => $title,
            'slug'                  => $this->generateUniqueSlug($artistId, $title),
            'audio_uri'             => $audioUri,
            'cover_url'             => $coverUrl,
            'duration_seconds'      => $durationSeconds,
            'track_number'          => $trackNumber,
            'genre'                 => $genre,
            'lyrics'                => $lyrics,
            'uploaded_by_user_id'   => $uploaderId,
            'uploaded_by_username'  => $uploaderUsername,
        ]);
    }

    public function update(int $id, array $data): int
    {
        $allowed = array_intersect_key($data, array_flip([
            'title', 'cover_url', 'genre', 'lyrics', 'track_number', 'album_id',
        ]));
        if (empty($allowed)) {
            return 0;
        }
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update($allowed);
    }

    public function remove(int $id): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['status' => 'removed']);
    }

    public function incrementPlaysCount(int $id, int $by = 1): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $id)->value('plays_count');
        $this->database->table(self::TABLE)->where('id', '=', $id)->update([
            'plays_count' => ((int) $current) + $by,
        ]);
    }

    public function adjustLikesCount(int $id, int $delta): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $id)->value('likes_count');
        $this->database->table(self::TABLE)->where('id', '=', $id)->update([
            'likes_count' => max(0, ((int) $current) + $delta),
        ]);
    }

    /** @param int[] $ids */
    public function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        return $this->database->table(self::TABLE)
            ->where('status', '!=', 'removed')
            ->whereIn('id', $ids)
            ->get();
    }

    private function generateUniqueSlug(int $artistId, string $title): string
    {
        $base = $this->slugify($title);
        $slug = $base;
        $i = 2;

        while ($this->findByArtistAndSlug($artistId, $slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? substr($text, 0, 180) : 'track';
    }
}
