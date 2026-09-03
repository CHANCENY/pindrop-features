<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

use Simp\Pindrop\Database\DatabaseService;

class FollowService
{
    private const TABLE = 'music_follows';

    public function __construct(protected DatabaseService $database) {}

    public function toggle(int $userId, int $artistId): bool
    {
        $existing = $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('artist_id', '=', $artistId)
            ->first();

        if ($existing) {
            $this->database->table(self::TABLE)->where('id', '=', $existing['id'])->delete();
            return false;
        }

        $this->database->table(self::TABLE)->insert([
            'user_id'   => $userId,
            'artist_id' => $artistId,
        ]);
        return true;
    }

    public function isFollowing(int $userId, int $artistId): bool
    {
        return $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('artist_id', '=', $artistId)
            ->exists();
    }

    /** Artist IDs a user follows, most-recently-followed first. */
    public function followedArtistIds(int $userId, int $limit = 100): array
    {
        $rows = $this->database->table(self::TABLE)
            ->select(['artist_id'])
            ->where('user_id', '=', $userId)
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return array_map(static fn ($r) => (int) $r['artist_id'], $rows);
    }
}
