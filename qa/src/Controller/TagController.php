<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\qa\src\Services\QuestionService;
use Simp\Pindrop\Modules\qa\src\Services\SeoService;
use Simp\Pindrop\Modules\qa\src\Services\TagService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TagController extends ControllerBase
{
    public function __construct(
        protected TagService $tags,
        protected QuestionService $questions,
        protected SeoService $seo
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('qa.tag'),
            $container->get('qa.question'),
            $container->get('qa.seo'),
        );
    }

    /** GET /tags */
    public function index(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig('@qa/tag_list.html.twig', [
            'tags' => $this->tags->all(),
            'meta' => [
                'title'       => 'Tags — Q&A',
                'description' => 'Browse all topic tags used across the community.',
            ],
        ]);
    }

    /** GET /tag/{slug} */
    public function view(Request $request, string $route_name, array $options): Response
    {
        $slug = (string) $request->query->get('slug');
        $tag = $this->tags->findBySlug($slug);

        if (!$tag) {
            return $this->renderTwig('@qa/404.html.twig', [], 404);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $questions = $this->questions->listQuestions(['tag_id' => $tag['id'], 'order' => 'newest'], $page, 20);

        $baseUrl = $request->getSchemeAndHttpHost();

        return $this->renderTwig('@qa/tag_view.html.twig', [
            'tag'       => $tag,
            'questions' => $questions,
            'related'   => $this->tags->related((int) $tag['id'], 10),
            'page'      => $page,
            'seo_meta'  => [
                'title'       => $tag['name'] . ' Questions — Q&A',
                'description' => $tag['description'] ?: ('Questions tagged with ' . $tag['name'] . '.'),
                'canonical'   => rtrim($baseUrl, '/') . '/tag/' . $tag['slug'],
            ],
        ]);
    }
}
