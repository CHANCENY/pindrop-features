<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class AnswerService
{
    private const TABLE = 'qa_answers';

    public function __construct(
        protected DatabaseService $database,
        protected LoggerInterface $logger
    ) {}

    public function forQuestion(int $questionId, string $order = 'votes'): array
    {
        $query = $this->database->table(self::TABLE)
            ->where('question_id', '=', $questionId)
            ->where('status', '=', 'visible');

        match ($order) {
            'newest' => $query->latest('created_at'),
            'oldest' => $query->oldest('created_at'),
            default  => $query->orderBy('is_accepted', 'DESC')->orderBy('votes_count', 'DESC'),
        };

        return $query->get();
    }

    public function find(int $id): ?array
    {
        return $this->database->table(self::TABLE)
            ->where('id', '=', $id)
            ->where('status', '=', 'visible')
            ->first();
    }

    /** See QuestionService::create() docblock re: why author info is denormalized here. */
    public function create(
        int $questionId,
        int $userId,
        string $body,
        string $authorUsername = '',
        ?string $authorAvatarUrl = null
    ): int {
        return $this->database->table(self::TABLE)->insert([
            'question_id'       => $questionId,
            'user_id'           => $userId,
            'author_username'   => $authorUsername,
            'author_avatar_url' => $authorAvatarUrl,
            'body'              => $body,
        ]);
    }

    public function update(int $id, string $body): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['body' => $body]);
    }

    public function softDelete(int $id): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['status' => 'deleted']);
    }

    public function markAccepted(int $id, int $questionId): void
    {
        // Only one accepted answer per question.
        $this->database->table(self::TABLE)
            ->where('question_id', '=', $questionId)
            ->update(['is_accepted' => 0]);

        $this->database->table(self::TABLE)
            ->where('id', '=', $id)
            ->update(['is_accepted' => 1]);
    }

    public function unmarkAccepted(int $questionId): void
    {
        $this->database->table(self::TABLE)
            ->where('question_id', '=', $questionId)
            ->update(['is_accepted' => 0]);
    }

    public function adjustVotesCount(int $answerId, int $delta): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $answerId)->value('votes_count');
        $this->database->table(self::TABLE)->where('id', '=', $answerId)->update([
            'votes_count' => ((int) $current) + $delta,
        ]);
    }

    /** Fallback snapshot source if the user has answers but no questions yet. */
    public function latestAuthorSnapshot(int $userId): ?array
    {
        return $this->database->table(self::TABLE)
            ->select(['author_username', 'author_avatar_url'])
            ->where('user_id', '=', $userId)
            ->latest('created_at')
            ->first();
    }

    public function countForUser(int $userId): int
    {
        return $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('status', '=', 'visible')
            ->count();
    }

    public function forUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        return $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('status', '=', 'visible')
            ->latest('created_at')
            ->forPage($page, $perPage)
            ->get();
    }
}
