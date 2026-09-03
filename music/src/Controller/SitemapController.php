<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SitemapController
 *
 * Same SimpleXMLElement approach proven in the qa plugin's SeoController.
 */
class SitemapController extends ControllerBase
{
    public function __construct(protected DatabaseService $database)
    {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static($container->get('database'));
    }

    /** GET /music/sitemap.xml */
    public function index(Request $request, string $route_name, array $options): Response
    {
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>');

        $artists = $this->database->table('music_artists')->where('status', '=', 'active')->select(['slug', 'updated_at'])->get();
        foreach ($artists as $artist) {
            $this->addUrl($xml, $baseUrl . '/music/artist/' . $artist['slug'], $artist['updated_at'], '0.8');
        }

        $albums = $this->database->table('music_albums AS al')
            ->select(['al.slug AS album_slug', 'al.updated_at', 'ar.slug AS artist_slug'])
            ->join('music_artists AS ar', 'ar.id', '=', 'al.artist_id')
            ->where('al.status', '=', 'published')
            ->get();
        foreach ($albums as $album) {
            $this->addUrl($xml, $baseUrl . '/music/artist/' . $album['artist_slug'] . '/album/' . $album['album_slug'], $album['updated_at'], '0.7');
        }

        $tracks = $this->database->table('music_tracks AS t')
            ->select(['t.slug AS track_slug', 't.updated_at', 'ar.slug AS artist_slug'])
            ->join('music_artists AS ar', 'ar.id', '=', 't.artist_id')
            ->where('t.status', '=', 'published')
            ->limit(5000)
            ->get();
        foreach ($tracks as $track) {
            $this->addUrl($xml, $baseUrl . '/music/artist/' . $track['artist_slug'] . '/track/' . $track['track_slug'], $track['updated_at'], '0.6');
        }

        return $this->render($xml->asXML() ?: '', 200, ['Content-Type' => 'application/xml']);
    }

    private function addUrl(\SimpleXMLElement $xml, string $loc, string $lastmod, string $priority): void
    {
        $url = $xml->addChild('url');
        $url->addChild('loc', htmlspecialchars($loc));
        $url->addChild('lastmod', date('c', strtotime($lastmod)));
        $url->addChild('changefreq', 'weekly');
        $url->addChild('priority', $priority);
    }
}
