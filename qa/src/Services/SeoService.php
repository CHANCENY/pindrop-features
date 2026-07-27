<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Services;

/**
 * SeoService
 *
 * Pure helper — no DB access. Builds the meta tag set and JSON-LD structured
 * data for a question page. Templates call this via the `qa_seo` Twig
 * function (see src/Twig/QaTwigExtension.php) rather than instantiating it
 * directly, but it's also a plain injectable service for controller use.
 */
class SeoService
{
    /**
     * @param array $question  row from qa_questions
     * @param array $author    row from core users table (or ['username' => ...])
     * @param array $answers   rows from qa_answers, used for FAQ/answer schema
     * @param string $baseUrl  e.g. https://example.com
     */
    public function metaTags(array $question, array $author, string $baseUrl): array
    {
        $title = $question['title'] . ' — Q&A';
        $description = $question['meta_description'] ?? $this->excerpt($question['body'] ?? '', 160);
        $canonical = rtrim($baseUrl, '/') . '/questions/' . $question['slug'];

        return [
            'title'       => $title,
            'description' => $description,
            'canonical'   => $canonical,
            'og' => [
                'og:type'        => 'article',
                'og:title'       => $title,
                'og:description' => $description,
                'og:url'         => $canonical,
            ],
            'twitter' => [
                'twitter:card'        => 'summary_large_image',
                'twitter:title'       => $title,
                'twitter:description' => $description,
            ],
        ];
    }

    /**
     * Question + (optionally) AcceptedAnswer JSON-LD, per schema.org/QAPage.
     * Returns a PHP array ready for json_encode() in the template.
     */
    public function questionJsonLd(array $question, array $author, array $answers, string $baseUrl): array
    {
        $canonical = rtrim($baseUrl, '/') . '/questions/' . $question['slug'];

        $suggestedAnswers = [];
        $acceptedAnswer = null;

        foreach ($answers as $answer) {
            $node = [
                '@type'      => 'Answer',
                'text'       => strip_tags($answer['body']),
                'dateCreated' => $this->toIso($answer['created_at']),
                'upvoteCount' => (int) ($answer['votes_count'] ?? 0),
                'url'        => $canonical . '#answer-' . $answer['id'],
            ];

            if (!empty($answer['is_accepted'])) {
                $acceptedAnswer = $node;
            } else {
                $suggestedAnswers[] = $node;
            }
        }

        $questionNode = [
            '@context'  => 'https://schema.org',
            '@type'     => 'QAPage',
            'mainEntity' => array_filter([
                '@type'          => 'Question',
                'name'           => $question['title'],
                'text'           => strip_tags($question['body'] ?? ''),
                'answerCount'    => (int) ($question['answers_count'] ?? 0),
                'upvoteCount'    => (int) ($question['votes_count'] ?? 0),
                'dateCreated'    => $this->toIso($question['created_at']),
                'author'         => [
                    '@type' => 'Person',
                    'name'  => $author['username'] ?? 'Anonymous',
                ],
                'acceptedAnswer' => $acceptedAnswer,
                'suggestedAnswer' => !empty($suggestedAnswers) ? $suggestedAnswers : null,
            ]),
        ];

        return $questionNode;
    }

    /** Simple FAQ schema for a set of Q/A pairs (e.g. a tag page's top questions). */
    public function faqJsonLd(array $questionAnswerPairs): array
    {
        $entities = [];
        foreach ($questionAnswerPairs as $pair) {
            if (empty($pair['question']) || empty($pair['answer'])) {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name'  => $pair['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => strip_tags($pair['answer']),
                ],
            ];
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    public function breadcrumbJsonLd(array $items, string $baseUrl): array
    {
        $list = [];
        foreach (array_values($items) as $i => $item) {
            $list[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $item['name'],
                'item'     => rtrim($baseUrl, '/') . $item['path'],
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $list,
        ];
    }

    private function excerpt(string $html, int $length): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }

    private function toIso(mixed $datetime): string
    {
        if ($datetime instanceof \DateTimeInterface) {
            return $datetime->format(DATE_ATOM);
        }
        try {
            return (new \DateTime((string) $datetime))->format(DATE_ATOM);
        } catch (\Exception) {
            return '';
        }
    }
}
