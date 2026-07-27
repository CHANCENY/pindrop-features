<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\qa\src\Services\QuestionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SeoController extends ControllerBase
{
    public function __construct(protected QuestionService $questions)
    {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static($container->get('qa.question'));
    }

    /**
     * GET /sitemap-questions.xml
     *
     * Registered here as its own dedicated sitemap file rather than trying
     * to merge into a hypothetical site-wide sitemap. If core or another
     * plugin later provides a sitemap index, add
     * <sitemap><loc>{baseUrl}/sitemap-questions.xml</loc></sitemap> to it.
     */
    public function sitemap(Request $request, string $route_name, array $options): Response
    {
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $page = max(1, (int) $request->query->get('page', 1));
        $questions = $this->questions->listQuestions(['status' => 'open'], $page, 5000);

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>');

        foreach ($questions as $question) {
            $url = $xml->addChild('url');
            $url->addChild('loc', htmlspecialchars($baseUrl . '/questions/' . $question['slug']));
            $url->addChild('lastmod', date('c', strtotime((string) $question['updated_at'])));
            $url->addChild('changefreq', 'weekly');
            $url->addChild('priority', '0.7');
        }

        return $this->render($xml->asXML() ?: '', 200, ['Content-Type' => 'application/xml']);
    }

    /** GET /questions/feed — RSS 2.0 of the latest questions. */
    public function rss(Request $request, string $route_name, array $options): Response
    {
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $questions = $this->questions->latest(30);

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"/>');
        $channel = $xml->addChild('channel');
        $channel->addChild('title', 'Latest Questions — Q&A');
        $channel->addChild('link', $baseUrl . '/questions');
        $channel->addChild('description', 'The latest questions from the community.');

        foreach ($questions as $question) {
            $item = $channel->addChild('item');
            $item->addChild('title', htmlspecialchars($question['title']));
            $link = $baseUrl . '/questions/' . $question['slug'];
            $item->addChild('link', $link);
            $item->addChild('guid', $link);
            $item->addChild('pubDate', date(DATE_RSS, strtotime((string) $question['created_at'])));
            $item->addChild('description', htmlspecialchars(mb_substr(strip_tags($question['body']), 0, 300)));
        }

        return $this->render($xml->asXML() ?: '', 200, ['Content-Type' => 'application/rss+xml']);
    }
}
