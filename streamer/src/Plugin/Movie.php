<?php

namespace Simp\Pindrop\Modules\streamer\src\Plugin;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\streamer\src\Http\Http;
use Simp\Pindrop\Modules\streamer\src\Services\Authentication;
use Simp\Pindrop\Modules\streamer\src\Services\Logs;

class Movie
{
    protected DatabaseService $databaseService;

    public function __construct(protected Http $http, protected Authentication $authentication)
    {
        $this->databaseService = \getAppContainer()->get('database');
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function searchMovie(array $params): array
    {
        if ($this->authentication->isAuthenticated()) {
            $this->http->clear();
            $paramsValidated = Http::parseParams($params, 'endpoint.movies.search.params');
            $this->http->setParams($paramsValidated);
            $this->http->request($this->http->getConfig()->get('endpoint.movies.search.path'));
            return Http::parseBodyFields($this->http->getResponseBody()['results'] ?? [], 'endpoint.movies.search.results');
        }

        return [];
    }

    /**
     * Get movie from tmdb database
     * @param array $params
     * @param bool $respons
     * @return array
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getMovie(array $params, bool $respons = false): array
    {
        if ($this->authentication->isAuthenticated()) {
            $this->http->clear();
            $paramsValidated = Http::parseParams($params, 'endpoint.movies.detail.params');
            $this->http->setParams($paramsValidated);
            $this->http->request($this->http->getConfig()->get('endpoint.movies.detail.path'));

            if ($respons) {
                return $this->http->getResponseBody();
            }
            return Http::getValue($this->http->getResponseBody(), 'endpoint.movies.detail.results');
        }
        return [];
    }

    public function getImages(array $params): array
    {
        if ($this->authentication->isAuthenticated()) {
            $this->http->clear();
            $paramsValidated = Http::parseParams($params, 'endpoint.movies.images.params');
            $this->http->setParams($paramsValidated);
            $this->http->request($this->http->getConfig()->get('endpoint.movies.images.path'));
            return Http::getValue($this->http->getResponseBody(), 'endpoint.movies.images.results');
        }
        return [];
    }

    public function getVideos(array $params): array
    {
        if ($this->authentication->isAuthenticated()) {
            $this->http->clear();
            $paramsValidated = Http::parseParams($params, 'endpoint.movies.videos.params');
            $this->http->setParams($paramsValidated);
            $this->http->request($this->http->getConfig()->get('endpoint.movies.videos.path'));
            return Http::parseBodyFields($this->http->getResponseBody()['results'] ?? [], 'endpoint.movies.videos.results');
        }
        return [];
    }



    public function createMovie(array $params): bool|int
    {
        try{
            $query = "INSERT INTO movies (video_file_id, video_path, featured, release_date, title, genres, duration, rating, popularity, description, thumbnail_path, imdb_id) VALUES (:video_file_id, :video_path, :featured, :release_date, :title, :genres, :duration, :rating, :popularity, :description, :thumbnail_path, :imdb_id)";
            $st = $this->databaseService->query($query, ...$params);
            Logs::addLog($params['title'], Logs::MOVIE_UPLOADED);
            return $this->databaseService->lastInsertId();
        }catch (\Throwable $e) {
            Message::error($e->getMessage());
            return false;
        }
    }

    /**
     * @throws DatabaseException
     */
    public function getMoviesLocally(array $params): array
    {
        $page = (int)($params['page'] ?? 1);
        $limit = (int)($params['limit'] ?? 10);
        $offset = ($page - 1) * $limit;
        
        $whereConditions = [];
        $bindParams = [];
        
        // Build WHERE conditions based on filters
        if (!empty($params['title'])) {
            $whereConditions[] = "title LIKE :title";
            $bindParams['title'] = '%' . $params['title'] . '%';
        }
        
        if (!empty($params['genre'])) {
            $whereConditions[] = "JSON_CONTAINS(genres, :genre)";
            $bindParams['genre'] = json_encode(['value'=>$params['genre']]);
        }
        
        if (!empty($params['year_from'])) {
            $whereConditions[] = "YEAR(release_date) >= :year_from";
            $bindParams['year_from'] = $params['year_from'];
        }
        
        if (!empty($params['year_to'])) {
            $whereConditions[] = "YEAR(release_date) <= :year_to";
            $bindParams['year_to'] = $params['year_to'];
        }
        
        if (!empty($params['rating_min'])) {
            $whereConditions[] = "rating >= :rating_min";
            $bindParams['rating_min'] = $params['rating_min'];
        }
        
        if (isset($params['featured']) && is_numeric($params['featured'])) {
            $whereConditions[] = "featured = :featured";
            $bindParams['featured'] = $params['featured'];
        }
        if (!empty($params['imdb_id'])) {
            $whereConditions[] = "imdb_id = :imdb_id";
            $bindParams["imdb_id"] = $params['imdb_id'];
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM movies $whereClause";
        $countStmt = $this->databaseService->query($countQuery, ...$bindParams);
        $total = $countStmt->fetch()['total'];
        
        // Get paginated results
        $query = "SELECT * FROM movies $whereClause ORDER BY release_date DESC LIMIT :limit OFFSET :offset";
        $bindParams['limit'] = $limit;
        $bindParams['offset'] = $offset;

        $stmt = $this->databaseService->query($query, ...$bindParams);
        $movies = $stmt->fetchAll();
        
        // Parse JSON genres for each movie
        foreach ($movies as &$movie) {
            if (isset($movie['genres'])) {
                $decodedGenres = json_decode($movie['genres'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedGenres)) {
                    $movie['genres'] = $decodedGenres;
                } else {
                    // If JSON decode fails, keep as string or set to empty array
                    $movie['genres'] = is_string($movie['genres']) ? $movie['genres'] : [];
                }
            }
        }
        
        return [
            'movies' => $movies,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit),
                'has_next' => $page < ceil($total / $limit),
                'has_prev' => $page > 1
            ]
        ];
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getGenres(array $params): array
    {
        if (isset($_SESSION['genres'])) {
            return $_SESSION['genres'];
        }

        if ($this->authentication->isAuthenticated()) {
            $this->http->clear();
            $paramsValidated = Http::parseParams($params, "endpoint.genres.params");
            $this->http->setParams($paramsValidated);
            $this->http->request($this->http->getConfig()->get('endpoint.genres.path'));
            $genres = Http::parseBodyFields($this->http->getResponseBody()['genres'] ?? [], 'endpoint.genres.results');
            $_SESSION['genres'] = $genres;
            return $genres;
        }
        return [];
    }

    public function getMovieLocally(int $id)
    {
        $query = "SELECT * FROM movies WHERE id = :id";
        $stmt = $this->databaseService->query($query, ...$i=['id' => $id]);
        return $stmt->fetch();
    }

    public function editMovie(int $id, array $params)
    {
        try{
            $params['id'] = $id;
            $query = "UPDATE movies SET video_file_id = :video_file_id, video_path = :video_path, featured = :featured, release_date = :release_date, title = :title, genres = :genres, duration = :duration, rating = :rating, popularity = :popularity, description = :description, thumbnail_path = :thumbnail_path, imdb_id = :imdb_id WHERE id = :id";
            $st = $this->databaseService->query($query, ...$params);
            Logs::addLog($params['title'], Logs::MOVIE_EDITED);
            return $st->rowCount();
        }catch (\Throwable $e) {
            Message::error($e->getMessage());
            return false;
        }
    }

    /**
     * @throws DatabaseException
     */
    public function getRecentAddedMoviesLocally(int $limit = 10)
    {
        $query = "SELECT * FROM movies ORDER BY created_at DESC LIMIT :limit";
        $stmt = $this->databaseService->query($query, $limit);
        return $stmt->fetchAll();
    }

    public function getMoviesCountLocally()
    {
        $query = "SELECT COUNT(*) as total FROM movies";
        $stmt = $this->databaseService->query($query);
        return $stmt->fetch()['total'];
    }

    public function getTopRatedMoviesLocally(int $limit = 10)
    {
        $query = "SELECT * FROM movies ORDER BY rating DESC LIMIT :limit";
        $stmt = $this->databaseService->query($query, $limit);
        return $stmt->fetchAll();
    }

    public function deleteMovie(int $id): bool
    {
        try{
            $movie = $this->getMovieLocally($id);
            $query = "DELETE FROM movies WHERE id = :id";
            $st = $this->databaseService->query($query, ...$i=['id' => $id]);
            Logs::addLog($movie['title'], Logs::MOVIE_DELETED);
            return $st->rowCount();
        }catch (\Throwable $e) {
            Message::error($e->getMessage());
            return false;
        }
    }

    public function getRandomFeaturedMovie(int $limit = 10)
    {
        return $this->databaseService->query("SELECT * FROM movies WHERE featured = 1 ORDER BY RAND() LIMIT :limit",$limit)
            ->fetchAll();
    }

    /**
     * @throws DatabaseException
     */
    public function getMoviePlaying(int $limit = 10, ?string $ip = null)
    {
        $stmt = null;
        $query = "";
        if (empty($ip)) {
            $query = "SELECT * FROM player_logs WHERE event = 'timeupdate' AND current_time_played < duration ORDER BY created_at DESC LIMIT :limit";
            $stmt = $this->databaseService->query($query, $limit);
        }
        else {
            $query = "SELECT * FROM player_logs WHERE event = 'timeupdate' AND ip_address = :ip AND current_time_played < duration ORDER BY created_at DESC LIMIT :limit";
            $stmt = $this->databaseService->query($query, ...$i=['limit' => $limit, 'ip' => $ip]);
        }
        return $stmt->fetchAll();

    }

    /**
     * @throws DatabaseException
     */
    public function getMoviePlayingNow(int $limit = 10)
    {
        $query = "SELECT * FROM player_logs WHERE event='timeupdate' AND current_time_played < duration AND updated_at >= NOW() - INTERVAL 120 SECOND ORDER BY updated_at DESC LIMIT :limit";
        $stmt = $this->databaseService->query($query, $limit);
        return $stmt->fetchAll();
    }
}