<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\qa\src\Services\SearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SearchController extends ControllerBase
{
    public function __construct(protected SearchService $search)
    {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static($container->get('qa.search'));
    }

    /** GET /search?q=...&filter=newest|oldest|votes|views|unanswered|accepted */
    public function index(Request $request, string $route_name, array $options): Response
    {
        $term = (string) $request->query->get('q', '');
        $filter = (string) $request->query->get('filter', 'newest');
        $page = max(1, (int) $request->query->get('page', 1));

        $results = $term !== '' ? $this->search->search($term, $filter, $page, 20) : [];
        $tagResults = $term !== '' ? $this->search->searchTags($term, 5) : [];

        return $this->renderTwig('@qa/search_results.html.twig', [
            'term'        => $term,
            'filter'      => $filter,
            'page'        => $page,
            'results'     => $results,
            'tag_results' => $tagResults,
            'meta' => [
                'title'       => $term !== '' ? "Search: {$term} — Q&A" : 'Search — Q&A',
                'description' => 'Search questions by title, body, and tags.',
            ],
        ]);
    }
}
