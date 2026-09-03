<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

use Simp\Pindrop\Database\DatabaseService;

/**
 * ListeningHistoryService
 *
 * Per-user play history — separate from TrackService::incrementPlaysCount()
 * (the aggregate, public counter). History requires a real user_id (schema
 * is NOT NULL), so anonymous plays still bump the track's public play
 * count via TrackService but aren't recorded here — there's no user to
 * attribute the row to, and that's fine: "recently played"/recommendation
 * features are inherently a logged-in-user feature.
 */
class ListeningHistoryService
{
    private const TABLE = 'music_listening_history';

    public function __construct(protected DatabaseService $database) {}

    public function record(int $userId, int $trackId): void
    {
        $this->database->table(self::TABLE)->insert([
            'user_id'  => $userId,
            'track_id' => $trackId,
        ]);
    }

    /** Most recent distinct tracks a user has played, newest first. */
    public function recentTrackIdsForUser(int $userId, int $limit = 20): array
    {
        $rows = $this->database->table(self::TABLE)
            ->select(['track_id'])
            ->where('user_id', '=', $userId)
            ->latest('played_at')
            ->limit($limit * 3) // over-fetch since we dedupe below
            ->get();

        $seen = [];
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['track_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }
}
