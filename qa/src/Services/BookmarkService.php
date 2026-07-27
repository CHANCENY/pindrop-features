<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class BookmarkService
{
    private const TABLE = 'qa_bookmarks';

    public function __construct(
        protected DatabaseService $database,
        protected LoggerInterface $logger
    ) {}

    /** Toggle a bookmark. Returns true if now bookmarked, false if removed. */
    public function toggle(int $userId, int $questionId): bool
    {
        $existing = $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('question_id', '=', $questionId)
            ->first();

        if ($existing) {
            $this->database->table(self::TABLE)->where('id', '=', $existing['id'])->delete();
            return false;
        }

        $this->database->table(self::TABLE)->insert([
            'user_id'     => $userId,
            'question_id' => $questionId,
        ]);
        return true;
    }

    public function isBookmarked(int $userId, int $questionId): bool
    {
        return $this->database->table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('question_id', '=', $questionId)
            ->exists();
    }

    public function forUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        return $this->database->table(self::TABLE . ' AS b')
            ->select(['q.*'])
            ->join('qa_questions AS q', 'q.id', '=', 'b.question_id')
            ->where('b.user_id', '=', $userId)
            ->orderBy('b.created_at', 'DESC')
            ->forPage($page, $perPage)
            ->get();
    }
}
