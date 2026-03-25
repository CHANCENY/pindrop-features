<?php

namespace Simp\Pindrop\Modules\streamer\src\Autocomplete;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\streamer\src\Plugin\Movie;
use Simp\Pindrop\Modules\streamer\src\Plugin\Show;

class MovieAutoComplete
{
    protected Movie $movie;
    protected Show $show;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function __construct(protected DatabaseService $database)
    {
        $this->movie = \getAppContainer()->get("streamer.movie");
        $this->show = \getAppContainer()->get("streamer.show");
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \DateMalformedStringException
     */
    public function matchMovies(string $query, int $limit = 10, $sort = 'DESC', $sort_by = null): array
    {
        $results = $this->movie->searchMovie([
            'query' => $query,
        ]);
        return array_map(function ($result) {
            $data = new \DateTime($result['release_date']);
            $year = $data->format('Y');
            return [
                'value' => "{$result['title']} - {$year} ({$result['id']})",
                'label' => "{$result['title']} - {$year} ({$result['id']})"
            ];
        }, $results);
    }

    public function matchShows(string $query, int $limit = 10, $sort = 'DESC', $sort_by = null): array
    {
        $results = $this->show->searchShows([
            'query' => $query,
        ]);
        return array_map(function ($result) {
            $data = new \DateTime($result['first_air_date']);
            $year = $data->format('Y');
            return [
                'value' => "{$result['name']} - {$year} ({$result['id']})",
                'label' => "{$result['name']} - {$year} ({$result['id']})"
            ];
        }, $results);
    }

    /**
     * @param string $query
     * @param int $limit
     * @param $sort
     * @param $sort_by
     * @return mixed
     * @throws \DateMalformedStringException
     * @throws DatabaseException
     */
    public function matchLocalMovies(string $query, int $limit = 10, $sort = 'DESC', $sort_by = null): array
    {
        $queryLine = "SELECT * FROM movies WHERE title LIKE :title ORDER BY release_date DESC LIMIT :limit";
        $params = [
            "title" => "%{$query}%",
            "limit" => $limit,
        ];
        $results = $this->database->query($queryLine, ...$params)->fetchAll();

        return array_map(function ($result) {
            $data = new \DateTime($result['release_date']);
            $year = $data->format('Y');
            return [
                'value' => "{$result['title']} - {$year} ({$result['imdb_id']}-{$result['id']})",
                'label' => "{$result['title']} - {$year} ({$result['imdb_id']}-{$result['id']})"
            ];
        }, $results);

    }
}