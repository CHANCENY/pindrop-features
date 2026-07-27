<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class CommentService
{
    private const TABLE = 'qa_comments';
    private const ALLOWED_TYPES = ['question', 'answer'];

    public function __construct(
        protected DatabaseService $database,
        protected LoggerInterface $logger
    ) {}

    /**
     * Returns a flat list of comments for a commentable, each row carrying
     * its own `parent_id` so the template can build the nested tree itself
     * (kept flat here — nesting logic lives in Twig, not the service).
     */
    public function forCommentable(string $type, int $id): array
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return [];
        }

        return $this->database->table(self::TABLE)
            ->where('commentable_type', '=', $type)
            ->where('commentable_id', '=', $id)
            ->oldest('created_at')
            ->get();
    }

    /** See QuestionService::create() docblock re: why author info is denormalized here. */
    public function create(
        int $userId,
        string $type,
        int $commentableId,
        string $body,
        ?int $parentId = null,
        string $authorUsername = ''
    ): int {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid commentable type: {$type}");
        }

        return $this->database->table(self::TABLE)->insert([
            'user_id'          => $userId,
            'author_username'  => $authorUsername,
            'commentable_type' => $type,
            'commentable_id'   => $commentableId,
            'parent_id'        => $parentId,
            'body'             => $body,
        ]);
    }

    public function update(int $id, string $body): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['body' => $body]);
    }

    public function delete(int $id): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->delete();
    }

    public function find(int $id): ?array
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->first();
    }
}
