<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;
use Simp\Pindrop\Modules\music\src\Services\SearchService;
use Simp\Pindrop\Modules\music\src\Services\TrackPresenterService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SearchController extends ControllerBase
{
    public function __construct(
        protected SearchService $search,
        protected ArtistService $artists,
        protected MediaUrlService $mediaUrl,
        protected TrackPresenterService $presenter
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.search'),
            $container->get('music.artist'),
            $container->get('music.media_url'),
            $container->get('music.track_presenter'),
        );
    }

    /** GET /music/search?q=... */
    public function index(Request $request, string $route_name, array $options): Response
    {
        $term = (string) $request->query->get('q', '');
        $results = $this->search->search($term, 15);

        $artistsById = [];
        foreach ($results['tracks'] as $t) {
            $id = (int) $t['artist_id'];
            if (!isset($artistsById[$id])) {
                $artistsById[$id] = $this->artists->find($id) ?? ['name' => 'Unknown Artist', 'slug' => ''];
            }
        }

        foreach ($results['tracks'] as &$track) {
            $artist = $artistsById[(int) $track['artist_id']];
            $track['_cover'] = $this->mediaUrl->url($track['cover_url'] ?? null);
            $track['_artist'] = $artist;
            $track['_play_json'] = $this->presenter->presentAsAttribute($track, $artist);
        }
        unset($track);

        foreach ($results['artists'] as &$artist) {
            $artist['_avatar'] = $this->mediaUrl->url($artist['avatar_url'] ?? null);
        }
        unset($artist);

        foreach ($results['albums'] as &$album) {
            $album['_cover'] = $this->mediaUrl->url($album['cover_url'] ?? null);
            $album['_artist'] = $artistsById[(int) $album['artist_id']]
                ?? $this->artists->find((int) $album['artist_id'])
                ?? ['name' => 'Unknown Artist', 'slug' => ''];
        }
        unset($album);

        return $this->renderTwig('@music/search_results.html.twig', [
            'term'    => $term,
            'results' => $results,
            'meta' => [
                'title'       => $term !== '' ? "Search: {$term} — Music" : 'Search — Music',
                'description' => 'Search songs, albums, and artists.',
            ],
        ]);
    }
}
