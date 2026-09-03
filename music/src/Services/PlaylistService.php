<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

use Simp\Pindrop\Database\DatabaseService;

class PlaylistService
{
    private const TABLE = 'music_playlists';
    private const PIVOT = 'music_playlist_tracks';

    public function __construct(protected DatabaseService $database) {}

    public function find(int $id): ?array
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->first();
    }

    public function forUser(int $userId): array
    {
        return $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->orderBy('updated_at', 'DESC')
            ->get();
    }

    public function create(int $userId, string $username, string $title, ?string $description = null, bool $isPublic = false): int
    {
        return $this->database->table(self::TABLE)->insert([
            'user_id'         => $userId,
            'author_username' => $username,
            'slug'            => $this->generateUniqueSlug($userId, $title),
            'title'           => $title,
            'description'     => $description,
            'is_public'       => $isPublic ? 1 : 0,
        ]);
    }

    public function update(int $id, array $data): int
    {
        $allowed = array_intersect_key($data, array_flip(['title', 'description', 'cover_url', 'is_public']));
        if (empty($allowed)) {
            return 0;
        }
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update($allowed);
    }

    public function togglePublic(int $id): bool
    {
        $playlist = $this->find($id);
        $next = $playlist && (int) $playlist['is_public'] === 1 ? 0 : 1;
        $this->database->table(self::TABLE)->where('id', '=', $id)->update(['is_public' => $next]);
        return $next === 1;
    }

    public function delete(int $id): int
    {
        // music_playlist_tracks rows cascade via FK ON DELETE CASCADE.
        return $this->database->table(self::TABLE)->where('id', '=', $id)->delete();
    }

    /** Ordered track rows for a playlist, joined with the track data itself. */
    public function tracksFor(int $playlistId): array
    {
        return $this->database->table(self::PIVOT . ' AS pt')
            ->select(['pt.id AS entry_id', 'pt.position', 'pt.added_at', 't.*'])
            ->join('music_tracks AS t', 't.id', '=', 'pt.track_id')
            ->where('pt.playlist_id', '=', $playlistId)
            ->where('t.status', '=', 'published')
            ->orderBy('pt.position', 'ASC')
            ->get();
    }

    public function addTrack(int $playlistId, int $trackId): void
    {
        $maxPosition = $this->database->table(self::PIVOT)
            ->where('playlist_id', '=', $playlistId)
            ->orderBy('position', 'DESC')
            ->value('position');

        $this->database->table(self::PIVOT)->insert([
            'playlist_id' => $playlistId,
            'track_id'    => $trackId,
            'position'    => ((int) ($maxPosition ?? -1)) + 1,
        ]);

        $this->adjustTracksCount($playlistId, 1);
    }

    /** Removes a specific entry (not just "a track with this ID") — playlists allow duplicate tracks. */
    public function removeEntry(int $playlistId, int $entryId): void
    {
        $deleted = $this->database->table(self::PIVOT)
            ->where('id', '=', $entryId)
            ->where('playlist_id', '=', $playlistId)
            ->delete();

        if ($deleted > 0) {
            $this->adjustTracksCount($playlistId, -1);
        }
    }

    /** @param int[] $orderedEntryIds music_playlist_tracks.id values in the new desired order */
    public function reorder(int $playlistId, array $orderedEntryIds): void
    {
        foreach (array_values($orderedEntryIds) as $position => $entryId) {
            $this->database->table(self::PIVOT)
                ->where('id', '=', (int) $entryId)
                ->where('playlist_id', '=', $playlistId)
                ->update(['position' => $position]);
        }
    }

    private function adjustTracksCount(int $id, int $delta): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $id)->value('tracks_count');
        $this->database->table(self::TABLE)->where('id', '=', $id)->update([
            'tracks_count' => max(0, ((int) $current) + $delta),
        ]);
    }

    private function generateUniqueSlug(int $userId, string $title): string
    {
        $base = $this->slugify($title);
        $slug = $base;
        $i = 2;

        while ($this->database->table(self::TABLE)->where('user_id', '=', $userId)->where('slug', '=', $slug)->exists()) {
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
        return $text !== '' ? substr($text, 0, 180) : 'playlist';
    }
}
