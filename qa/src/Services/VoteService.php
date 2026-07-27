<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class VoteService
{
    private const TABLE = 'qa_votes';
    private const ALLOWED_TYPES = ['question', 'answer'];

    public function __construct(
        protected DatabaseService $database,
        protected LoggerInterface $logger
    ) {}

    /**
     * Cast (or toggle) a vote. One vote per user per votable is enforced by
     * the qa_votes unique key (user_id, votable_id, votable_type) — abuse
     * prevention lives at the schema level, not just in application code.
     *
     * Returns ['action' => 'created'|'switched'|'removed', 'delta' => int]
     * where `delta` is what the caller should add to the votable's
     * votes_count column.
     *
     * @throws \InvalidArgumentException
     */
    public function castVote(int $userId, string $votableType, int $votableId, string $type): array
    {
        if (!in_array($votableType, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid votable type: {$votableType}");
        }
        if (!in_array($type, ['upvote', 'downvote'], true)) {
            throw new \InvalidArgumentException("Invalid vote type: {$type}");
        }

        $existing = $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('votable_id', '=', $votableId)
            ->where('votable_type', '=', $votableType)
            ->first();

        if (!$existing) {
            $this->database->table(self::TABLE)->insert([
                'user_id'      => $userId,
                'votable_id'   => $votableId,
                'votable_type' => $votableType,
                'type'         => $type,
            ]);
            return ['action' => 'created', 'delta' => $type === 'upvote' ? 1 : -1];
        }

        if ($existing['type'] === $type) {
            // Clicking the same vote button again removes the vote.
            $this->database->table(self::TABLE)->where('id', '=', $existing['id'])->delete();
            return ['action' => 'removed', 'delta' => $type === 'upvote' ? -1 : 1];
        }

        // Switching from upvote to downvote or vice versa.
        $this->database->table(self::TABLE)->where('id', '=', $existing['id'])->update(['type' => $type]);
        return ['action' => 'switched', 'delta' => $type === 'upvote' ? 2 : -2];
    }

    public function userVoteFor(int $userId, string $votableType, int $votableId): ?string
    {
        $row = $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('votable_id', '=', $votableId)
            ->where('votable_type', '=', $votableType)
            ->select(['type'])
            ->first();

        return $row['type'] ?? null;
    }

    /**
     * Batch-fetch the current user's votes for a set of votables in one
     * query, so a question+answers list page doesn't do N+1 lookups.
     *
     * @param int[] $votableIds
     * @return array<int,string> votable_id => 'upvote'|'downvote'
     */
    public function userVotesFor(int $userId, string $votableType, array $votableIds): array
    {
        if (empty($votableIds)) {
            return [];
        }

        $rows = $this->database->table(self::TABLE)
            ->select(['votable_id', 'type'])
            ->where('user_id', '=', $userId)
            ->where('votable_type', '=', $votableType)
            ->whereIn('votable_id', $votableIds)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['votable_id']] = $row['type'];
        }
        return $map;
    }
}
