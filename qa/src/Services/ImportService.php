<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

/**
 * ImportService
 *
 * Bulk-imports questions from an array of rows shaped like:
 *   {
 *     "title": "...",
 *     "body": "...",
 *     "tags": "comma, separated, string"   // or ["array", "of", "tags"]
 *     "answers": ["answer text", ...]       // optional, 0 or more
 *   }
 *
 * Used by both the CLI command (cli/import.php, `./pindro qa:import <file>`)
 * and the admin upload UI (ModerationController::import()) — one code path,
 * so behavior can't drift between the two entry points.
 */
class ImportService
{
    public function __construct(
        protected QuestionService $questions,
        protected AnswerService $answers,
        protected TagService $tags
    ) {}

    /**
     * @param array $rows Decoded JSON array (see class docblock for shape).
     * @param int $importedByUserId  Attributed author's user ID. Use 0 for a
     *   system/no-owner import (fine — qa_questions.user_id has no FK to the
     *   core users table by design; see README).
     * @param string $importedByUsername  Denormalized display name stamped
     *   onto every imported question/answer — see QuestionService::create()
     *   docblock for why author info is denormalized rather than joined.
     *
     * @return array{imported:int, answers_imported:int, skipped:int, errors:string[]}
     */
    public function importRows(
        array $rows,
        int $importedByUserId = 0,
        string $importedByUsername = 'Import Bot'
    ): array {
        $result = ['imported' => 0, 'answers_imported' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $i => $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $body = trim((string) ($row['body'] ?? ''));

            if ($title === '' || $body === '') {
                $result['skipped']++;
                $result['errors'][] = "Row {$i}: missing title or body — skipped.";
                continue;
            }

            try {
                $questionId = $this->questions->create(
                    $importedByUserId,
                    $title,
                    $body,
                    null,
                    $importedByUsername
                );

                $tagNames = $this->normalizeTags($row['tags'] ?? []);
                if (!empty($tagNames)) {
                    $this->tags->syncQuestionTags($questionId, $tagNames);
                }

                $answerBodies = is_array($row['answers'] ?? null) ? $row['answers'] : [];
                $answersAdded = 0;
                foreach ($answerBodies as $answerBody) {
                    $answerBody = trim((string) $answerBody);
                    if ($answerBody === '') {
                        continue;
                    }
                    $this->answers->create($questionId, $importedByUserId, $answerBody, $importedByUsername);
                    $answersAdded++;
                }
                if ($answersAdded > 0) {
                    $this->questions->incrementAnswersCount($questionId, $answersAdded);
                    $result['answers_imported'] += $answersAdded;
                }

                $result['imported']++;
            } catch (\Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = "Row {$i} (\"{$title}\"): " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Parse a raw JSON string into rows and import them. Throws
     * \JsonException on malformed JSON, InvalidArgumentException if the
     * top-level structure isn't a list of objects.
     */
    public function importFromJson(string $json, int $importedByUserId = 0, string $importedByUsername = 'Import Bot'): array
    {
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($rows)) {
            throw new \InvalidArgumentException('Expected a JSON array of question objects at the top level.');
        }

        return $this->importRows($rows, $importedByUserId, $importedByUsername);
    }

    /** @param string|array $tags */
    private function normalizeTags(string|array $tags): array
    {
        if (is_array($tags)) {
            $list = $tags;
        } else {
            $list = explode(',', $tags);
        }

        return array_values(array_filter(array_map('trim', $list)));
    }
}
