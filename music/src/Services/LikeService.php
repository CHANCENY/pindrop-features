<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

use Simp\Pindrop\Database\DatabaseService;

class LikeService
{
    private const TABLE = 'music_likes';
    private const ALLOWED_TYPES = ['track', 'album'];

    public function __construct(protected DatabaseService $database) {}

    /** @throws \InvalidArgumentException */
    public function toggle(int $userId, string $type, int $id): bool
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid likeable type: {$type}");
        }

        $existing = $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('likeable_type', '=', $type)
            ->where('likeable_id', '=', $id)
            ->first();

        if ($existing) {
            $this->database->table(self::TABLE)->where('id', '=', $existing['id'])->delete();
            return false;
        }

        $this->database->table(self::TABLE)->insert([
            'user_id'       => $userId,
            'likeable_type' => $type,
            'likeable_id'   => $id,
        ]);
        return true;
    }

    public function isLiked(int $userId, string $type, int $id): bool
    {
        return $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('likeable_type', '=', $type)
            ->where('likeable_id', '=', $id)
            ->exists();
    }

    /**
     * Batch-check liked state for a set of IDs in one query — avoids N+1
     * lookups when rendering a track list for a logged-in user.
     *
     * @param int[] $ids
     * @return array<int,bool> id => liked
     */
    public function likedMap(int $userId, string $type, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = $this->database->table(self::TABLE)
            ->select(['likeable_id'])
            ->where('user_id', '=', $userId)
            ->where('likeable_type', '=', $type)
            ->whereIn('likeable_id', $ids)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['likeable_id']] = true;
        }
        return $map;
    }

    /** Liked track IDs, newest-liked first — the basis of the "Liked Songs" library section. */
    public function likedTrackIds(int $userId, int $limit = 200): array
    {
        $rows = $this->database->table(self::TABLE)
            ->select(['likeable_id'])
            ->where('user_id', '=', $userId)
            ->where('likeable_type', '=', 'track')
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return array_map(static fn ($r) => (int) $r['likeable_id'], $rows);
    }

    public function countFor(string $type, int $id): int
    {
        return $this->database->table(self::TABLE)
            ->where('likeable_type', '=', $type)
            ->where('likeable_id', '=', $id)
            ->count();
    }
}
