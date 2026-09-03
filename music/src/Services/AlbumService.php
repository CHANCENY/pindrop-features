<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

use Simp\Pindrop\Database\DatabaseService;

class AlbumService
{
    private const TABLE = 'music_albums';

    public function __construct(protected DatabaseService $database) {}

    public function find(int $id): ?array
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->first();
    }

    /** Album slugs are unique per-artist, not globally — same convention as qa's user-scoped playlist slugs. */
    public function findByArtistAndSlug(int $artistId, string $slug): ?array
    {
        return $this->database->table(self::TABLE)
            ->where('artist_id', '=', $artistId)
            ->where('slug', '=', $slug)
            ->first();
    }

    public function forArtist(int $artistId, int $limit = 50): array
    {
        return $this->database->table(self::TABLE)
            ->where('artist_id', '=', $artistId)
            ->where('status', '=', 'published')
            ->orderBy('release_date', 'DESC')
            ->limit($limit)
            ->get();
    }

    public function recent(int $limit = 12): array
    {
        return $this->database->table(self::TABLE)
            ->where('status', '=', 'published')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function search(string $term, int $limit = 10): array
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

    public function create(int $artistId, string $title, string $type = 'album', ?string $coverUrl = null, ?string $releaseDate = null): int
    {
        return $this->database->table(self::TABLE)->insert([
            'artist_id'    => $artistId,
            'slug'         => $this->generateUniqueSlug($artistId, $title),
            'title'        => $title,
            'type'         => in_array($type, ['album', 'single', 'ep', 'compilation'], true) ? $type : 'album',
            'cover_url'    => $coverUrl,
            'release_date' => $releaseDate,
        ]);
    }

    public function update(int $id, array $data): int
    {
        $allowed = array_intersect_key($data, array_flip(['title', 'cover_url', 'type', 'release_date']));
        if (empty($allowed)) {
            return 0;
        }
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update($allowed);
    }

    public function remove(int $id): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['status' => 'removed']);
    }

    public function adjustTracksCount(int $id, int $delta): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $id)->value('tracks_count');
        $this->database->table(self::TABLE)->where('id', '=', $id)->update([
            'tracks_count' => max(0, ((int) $current) + $delta),
        ]);
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
        return $text !== '' ? substr($text, 0, 180) : 'album';
    }
}
