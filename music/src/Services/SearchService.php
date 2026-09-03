<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

class SearchService
{
    public function __construct(
        protected ArtistService $artists,
        protected AlbumService $albums,
        protected TrackService $tracks
    ) {}

    /** @return array{artists: array, albums: array, tracks: array} */
    public function search(string $term, int $limitEach = 12): array
    {
        $term = trim($term);
        if ($term === '') {
            return ['artists' => [], 'albums' => [], 'tracks' => []];
        }

        return [
            'artists' => $this->artists->search($term, $limitEach),
            'albums'  => $this->albums->search($term, $limitEach),
            'tracks'  => $this->tracks->search($term, $limitEach),
        ];
    }
}
