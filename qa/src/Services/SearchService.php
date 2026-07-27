<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

/**
 * SearchService
 *
 * Uses the FULLTEXT(title, body) index defined on qa_questions in
 * qa_schema.sql. This is a native-MySQL search — swapping in Elasticsearch
 * or another engine later only means replacing this class; nothing else
 * in the plugin depends on the search implementation.
 */
class SearchService
{
    public function __construct(
        protected DatabaseService $database,
        protected LoggerInterface $logger
    ) {}

    /**
     * @param string $term
     * @param string $filter one of: newest|oldest|votes|views|unanswered|accepted
     */
    public function search(string $term, string $filter = 'newest', int $page = 1, int $perPage = 20): array
    {
        $term = trim($term);

        $query = $this->database->table('qa_questions')
            ->where('status', '!=', 'deleted');

        if ($term !== '') {
            // MATCH ... AGAINST in natural language mode against the FULLTEXT index.
            $query->whereRaw('MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)', [$term]);
        }

        match ($filter) {
            'oldest'     => $query->oldest('created_at'),
            'votes'      => $query->orderBy('votes_count', 'DESC'),
            'views'      => $query->orderBy('views', 'DESC'),
            'unanswered' => $query->where('answers_count', '=', 0)->latest('created_at'),
            'accepted'   => $query->whereNotNull('accepted_answer_id')->latest('created_at'),
            default      => $query->latest('created_at'),
        };

        return $query->forPage($page, $perPage)->get();
    }

    public function searchTags(string $term, int $limit = 10): array
    {
        $term = trim($term);
        $query = $this->database->table('qa_tags');

        if ($term !== '') {
            $query->whereRaw('name LIKE ?', ['%' . $term . '%']);
        }

        return $query->orderBy('questions_count', 'DESC')->limit($limit)->get();
    }
}
