<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

/**
 * QuestionService
 *
 * Plain service class over DatabaseService::table() (the QueryBuilder).
 * Deliberately does NOT extend StorageEntity — StorageEntity's save()/load()
 * paths call methods that no longer exist on the current DatabaseService,
 * so every write in this plugin goes through the QueryBuilder directly,
 * matching the pattern used by the `farm` plugin's service layer.
 */
class QuestionService
{
    private const TABLE = 'qa_questions';

    public function __construct(
        protected DatabaseService $database,
        protected LoggerInterface $logger
    ) {}

    /**
     * Paginated question list with optional filters.
     *
     * @param array{status?:string, tag_id?:int, user_id?:int, order?:string} $filters
     *   order: 'newest' | 'oldest' | 'votes' | 'views' | 'unanswered' | 'active'
     */
    public function listQuestions(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = $this->database->table('qa_questions AS q')
            ->select(['q.*'])
            ->where('q.status', '!=', 'deleted');

        if (!empty($filters['status'])) {
            $query->where('q.status', '=', $filters['status']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('q.user_id', '=', $filters['user_id']);
        }

        if (!empty($filters['tag_id'])) {
            $query->join('qa_question_tags AS qt', 'qt.question_id', '=', 'q.id')
                ->where('qt.tag_id', '=', $filters['tag_id']);
        }

        if (($filters['order'] ?? 'newest') === 'unanswered') {
            $query->where('q.answers_count', '=', 0);
        }

        match ($filters['order'] ?? 'newest') {
            'oldest'  => $query->oldest('q.created_at'),
            'votes'   => $query->orderBy('q.votes_count', 'DESC'),
            'views'   => $query->orderBy('q.views', 'DESC'),
            'active'  => $query->orderBy('q.updated_at', 'DESC'),
            default   => $query->latest('q.created_at'),
        };

        return $query->forPage($page, $perPage)->get();
    }

    public function countQuestions(array $filters = []): int
    {
        $query = $this->database->table(self::TABLE)->where('status', '!=', 'deleted');

        if (!empty($filters['status'])) {
            $query->where('status', '=', $filters['status']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', '=', $filters['user_id']);
        }

        return $query->count();
    }

    public function find(int $id): ?array
    {
        return $this->database->table(self::TABLE)
            ->where('id', '=', $id)
            ->where('status', '!=', 'deleted')
            ->first();
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->database->table(self::TABLE)
            ->where('slug', '=', $slug)
            ->where('status', '!=', 'deleted')
            ->first();
    }

    /**
     * Create a new question. Returns the new question's ID.
     *
     * $authorUsername/$authorAvatarUrl are snapshotted from the poster's own
     * session data (CurrentUser::getUser(), no DB query) at write time —
     * NOT looked up from the core `users` table, because DatabasePermissionGuard
     * only lets 'admin'/'super_admin' (or an explicit db.system.read grant)
     * SELECT from core tables. Denormalizing avoids that wall entirely and
     * avoids an extra query per question/answer/comment render.
     */
    public function create(
        int $userId,
        string $title,
        string $body,
        ?string $metaDescription = null,
        string $authorUsername = '',
        ?string $authorAvatarUrl = null
    ): int {
        $slug = $this->generateUniqueSlug($title);

        return $this->database->table(self::TABLE)->insert([
            'user_id'           => $userId,
            'author_username'   => $authorUsername,
            'author_avatar_url' => $authorAvatarUrl,
            'title'             => $title,
            'slug'              => $slug,
            'body'              => $body,
            'meta_description'  => $metaDescription,
            'status'            => 'open',
            'published_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, array $data): int
    {
        $allowed = array_intersect_key($data, array_flip([
            'title', 'body', 'meta_description', 'status', 'accepted_answer_id',
        ]));

        if (empty($allowed)) {
            return 0;
        }

        return $this->database->table(self::TABLE)->where('id', '=', $id)->update($allowed);
    }

    /** Soft delete — keeps the row for audit/edit-history purposes. */
    public function softDelete(int $id): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['status' => 'deleted']);
    }

    public function close(int $id): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['status' => 'closed']);
    }

    public function reopen(int $id): int
    {
        return $this->database->table(self::TABLE)->where('id', '=', $id)->update(['status' => 'open']);
    }

    public function incrementViews(int $id): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $id)->value('views');
        $this->database->table(self::TABLE)->where('id', '=', $id)->update([
            'views' => ((int) $current) + 1,
        ]);
    }

    public function setAcceptedAnswer(int $questionId, ?int $answerId): void
    {
        $this->database->table(self::TABLE)->where('id', '=', $questionId)->update([
            'accepted_answer_id' => $answerId,
        ]);
    }

    public function incrementAnswersCount(int $questionId, int $by = 1): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $questionId)->value('answers_count');
        $this->database->table(self::TABLE)->where('id', '=', $questionId)->update([
            'answers_count' => max(0, ((int) $current) + $by),
        ]);
    }

    public function adjustVotesCount(int $questionId, int $delta): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $questionId)->value('votes_count');
        $this->database->table(self::TABLE)->where('id', '=', $questionId)->update([
            'votes_count' => ((int) $current) + $delta,
        ]);
    }

    public function adjustBookmarksCount(int $questionId, int $delta): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $questionId)->value('bookmarks_count');
        $this->database->table(self::TABLE)->where('id', '=', $questionId)->update([
            'bookmarks_count' => max(0, ((int) $current) + $delta),
        ]);
    }

    /** Trending: most-viewed in the last 7 days, falling back to all-time votes. */
    public function trending(int $limit = 10): array
    {
        return $this->database->table(self::TABLE)
            ->where('status', '!=', 'deleted')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))
            ->orderBy('views', 'DESC')
            ->limit($limit)
            ->get();
    }

    public function unanswered(int $limit = 10): array
    {
        return $this->database->table(self::TABLE)
            ->where('status', '=', 'open')
            ->where('answers_count', '=', 0)
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Best-effort public display snapshot for a user, derived from their most
     * recent question — used for public profile pages, since the core
     * `users` table can't be queried outside admin/super_admin context.
     */
    public function latestAuthorSnapshot(int $userId): ?array
    {
        return $this->database->table(self::TABLE)
            ->select(['author_username', 'author_avatar_url'])
            ->where('user_id', '=', $userId)
            ->latest('created_at')
            ->first();
    }

    public function latest(int $limit = 10): array
    {
        return $this->database->table(self::TABLE)
            ->where('status', '!=', 'deleted')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Slugify the title and, if a collision exists, append -2, -3, ... until unique.
     */
    private function generateUniqueSlug(string $title): string
    {
        $base = $this->slugify($title);
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
        $text = substr($text, 0, 200);

        return $text !== '' ? $text : 'question';
    }
}
