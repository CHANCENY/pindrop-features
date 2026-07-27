<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class TagService
{
    private const TABLE = 'qa_tags';
    private const PIVOT = 'qa_question_tags';

    public function __construct(
        protected DatabaseService $database,
        protected LoggerInterface $logger
    ) {}

    public function all(): array
    {
        return $this->database->table(self::TABLE)->orderBy('questions_count', 'DESC')->get();
    }

    public function popular(int $limit = 20): array
    {
        return $this->database->table(self::TABLE)
            ->orderBy('questions_count', 'DESC')
            ->limit($limit)
            ->get();
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->database->table(self::TABLE)->where('slug', '=', $slug)->first();
    }

    public function findByName(string $name): ?array
    {
        return $this->database->table(self::TABLE)->where('name', '=', $name)->first();
    }

    /** Find-or-create a tag by display name, returning its ID. */
    public function findOrCreate(string $name): int
    {
        $name = trim($name);
        $existing = $this->findByName($name);
        if ($existing) {
            return (int) $existing['id'];
        }

        return $this->database->table(self::TABLE)->insert([
            'name' => $name,
            'slug' => $this->slugify($name),
        ]);
    }

    /**
     * Replace the tag set on a question with the given list of tag names.
     * Keeps qa_tags.questions_count in sync.
     *
     * @param string[] $tagNames
     */
    public function syncQuestionTags(int $questionId, array $tagNames): void
    {
        $existingTagIds = $this->database->table(self::PIVOT)
            ->where('question_id', '=', $questionId)
            ->pluck('tag_id');

        foreach ($existingTagIds as $tagId) {
            $this->adjustQuestionsCount((int) $tagId, -1);
        }

        $this->database->table(self::PIVOT)->where('question_id', '=', $questionId)->delete();

        $tagNames = array_values(array_unique(array_filter(array_map('trim', $tagNames))));

        foreach ($tagNames as $name) {
            $tagId = $this->findOrCreate($name);
            $this->database->table(self::PIVOT)->insertIgnore([
                'question_id' => $questionId,
                'tag_id'      => $tagId,
            ]);
            $this->adjustQuestionsCount($tagId, 1);
        }
    }

    public function tagsForQuestion(int $questionId): array
    {
        return $this->database->table(self::PIVOT . ' AS qt')
            ->select(['t.id', 't.name', 't.slug'])
            ->join('qa_tags AS t', 't.id', '=', 'qt.tag_id')
            ->where('qt.question_id', '=', $questionId)
            ->get();
    }

    public function related(int $tagId, int $limit = 10): array
    {
        // Tags that co-occur with $tagId on the same questions, ranked by frequency.
        return $this->database->table(self::PIVOT . ' AS qt1')
            ->select(['t.id', 't.name', 't.slug'])
            ->join('qa_question_tags AS qt2', 'qt2.question_id', '=', 'qt1.question_id')
            ->join('qa_tags AS t', 't.id', '=', 'qt2.tag_id')
            ->where('qt1.tag_id', '=', $tagId)
            ->where('qt2.tag_id', '!=', $tagId)
            ->groupBy('t.id', 't.name', 't.slug')
            ->limit($limit)
            ->get();
    }

    private function adjustQuestionsCount(int $tagId, int $delta): void
    {
        $current = $this->database->table(self::TABLE)->where('id', '=', $tagId)->value('questions_count');
        $this->database->table(self::TABLE)->where('id', '=', $tagId)->update([
            'questions_count' => max(0, ((int) $current) + $delta),
        ]);
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-') ?: 'tag';
    }
}
