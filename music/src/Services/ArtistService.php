<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

use Simp\Pindrop\Database\DatabaseService;

/**
 * ArtistService
 *
 * Plain service over DatabaseService::table() (the QueryBuilder) — no
 * StorageEntity, for the same reason established in the qa plugin: it
 * calls a DatabaseService method that no longer exists on current core.
 */
class ArtistService
{
    private const TABLE = 'music_artists';

    public function __construct(protected DatabaseService $database) {}

    public function find(int $id): ?array
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->first();
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->database->table(self::TABLE)->where('slug', '=', $slug)->first();
    }

    /** Artist profile(s) owned/managed by a given user account. */
    public function forOwner(int $userId): array
    {
        return $this->database->table(self::TABLE)->where('owner_user_id', '=', $userId)->get();
    }

    public function popular(int $limit = 12): array
    {
        return $this->database->table(self::TABLE)
            ->where('status', '=', 'active')
            ->orderBy('follower_count', 'DESC')
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
            ->where('status', '=', 'active')
            ->whereRaw('MATCH(name, bio) AGAINST (? IN NATURAL LANGUAGE MODE)', [$term])
            ->limit($limit)
            ->get();
    }

    /**
     * Create an artist profile owned by $userId. $username is a
     * denormalized snapshot from the poster's own session data
     * (CurrentUser::getUser(), no DB query) — see QuestionService::create()
     * in the qa plugin for the full rationale; same constraint applies
     * here (core `users` table isn't readable outside admin/super_admin).
     */
    public function create(int $userId, string $username, string $name, ?string $bio = null, ?string $avatarUrl = null): int
    {
        return $this->database->table(self::TABLE)->insert([
            'slug'           => $this->generateUniqueSlug($name),
            'name'           => $name,
            'bio'            => $bio,
            'avatar_url'     => $avatarUrl,
            'owner_user_id'  => $userId,
            'owner_username' => $username,
        ]);
    }

    public function update(int $id, array $data): int
    {
        $allowed = array_intersect_key($data, array_flip(['name', 'bio', 'avatar_url']));
        if (empty($allowed)) {
            return 0;
        }
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update($allowed);
    }

    public function suspend(int $id): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['status' => 'suspended']);
    }

    public function reinstate(int $id): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['status' => 'active']);
    }

    public function adjustFollowerCount(int $id, int $delta): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $id)->value('follower_count');
        $this->database->table(self::TABLE)->where('id', '=', $id)->update([
            'follower_count' => max(0, ((int) $current) + $delta),
        ]);
    }

    public function adjustTracksCount(int $id, int $delta): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $id)->value('tracks_count');
        $this->database->table(self::TABLE)->where('id', '=', $id)->update([
            'tracks_count' => max(0, ((int) $current) + $delta),
        ]);
    }

    /** @param int[] $ids */
    public function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        return $this->database->table(self::TABLE)->whereIn('id', $ids)->get();
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = $this->slugify($name);
        $slug = $base;
        $i = 2;

        while ($this->database->table(self::TABLE)->where('slug', '=', $slug)->exists()) {
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
        return $text !== '' ? substr($text, 0, 180) : 'artist';
    }
}
