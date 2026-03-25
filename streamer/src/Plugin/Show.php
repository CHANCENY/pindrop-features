<?php

namespace Simp\Pindrop\Modules\streamer\src\Plugin;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\streamer\src\Http\Http;
use Simp\Pindrop\Modules\streamer\src\Services\Authentication;
use Simp\Pindrop\Modules\streamer\src\Services\Logs;

class Show
{

    private DatabaseService $databaseService;

    public function __construct(protected Http $http, protected Authentication $authentication)
    {
        $this->databaseService = \getAppContainer()->get('database');
    }

    public function searchShows(array $params): array
    {
        $this->http->clear();
        $paramsValidated = Http::parseParams($params, 'endpoint.show.search.params');
        $this->http->setParams($paramsValidated);
        $this->http->request($this->http->getConfig()->get('endpoint.show.search.path'));
        return Http::parseBodyFields($this->http->getResponseBody()['results'] ?? [], 'endpoint.show.search.results');
    }

    public function getShow(array $params, bool $response = false): array
    {
        if ($this->authentication->isAuthenticated()) {
            $this->http->clear();
            $paramsValidated = Http::parseParams($params, 'endpoint.show.detail.params');
            $this->http->setParams($paramsValidated);
            $this->http->request($this->http->getConfig()->get('endpoint.show.detail.path'));

            if ($response) {
                return $this->http->getResponseBody();
            }
            return Http::getValue($this->http->getResponseBody(), 'endpoint.show.detail.results');
        }
        return [];
    }

    public function createShow(array $params): bool|int
    {
        try{
            $query = "SELECT * FROM `series` WHERE imdb_id = :id";
            $stmt = $this->databaseService->query($query, $params['imdb_id']);
            $row = $stmt->fetch();
            if (empty($row)) {
                $query = "INSERT INTO series (title, genres, start_year, end_year, rating, status, description, language, country, poster, thumbnail_path, season_count,imdb_id) VALUES (:title, :genres, :start_year, :end_year, :rating, :status, :description, :language, :country, :poster, :thumbnail_path, :season_count,:imdb_id)";
                $st = $this->databaseService->query($query, ...$params);
                Logs::addLog($params['title'], Logs::SHOW_CREATED);
                return $this->databaseService->lastInsertId();
            }
            return $row['id'];
        }catch (\Exception $e){
            Message::error("Show Creation Error: ".$e->getMessage());
            return false;
        }
    }

    public function createSeason(array $params): bool|int
    {
        try{
            $query = "SELECT * FROM `seasons` WHERE imdb_id = :id";
            $stmt = $this->databaseService->query($query, ...$i=['id'=>$params['imdb_id']]);
            if (empty($stmt->fetch())) {
                $query = "INSERT INTO seasons (series_id, title, season_number, vote_average, episode_count, air_date, description, poster, thumbnail_path, imdb_id) VALUES (:series_id, :title, :season_number, :vote_average, :episode_count, :air_date, :description, :poster, :thumbnail_path, :imdb_id)";
                $st = $this->databaseService->query($query, ...$params);
                Logs::addLog($params['title'], Logs::SEASON_CREATED);
                return $this->databaseService->lastInsertId();
            }
            return $stmt->fetch()['id'] ?? false;
        }catch (\Throwable $e){
            Message::error("Season Creation Error: ".$e->getMessage());
            return false;
        }
    }

    public function getShowsLocally(array $params): array
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
            $whereConditions[] = "YEAR(start_year) >= :year_from";
            $bindParams['year_from'] = $params['year_from'];
        }
        
        if (!empty($params['year_to'])) {
            $whereConditions[] = "YEAR(end_year) <= :year_to";
            $bindParams['year_to'] = $params['year_to'];
        }
        
        if (!empty($params['rating_min'])) {
            $whereConditions[] = "rating >= :rating_min";
            $bindParams['rating_min'] = $params['rating_min'];
        }
        
        if (!empty($params['status'])) {
            $whereConditions[] = "status = :status";
            $bindParams['status'] = $params['status'];
        }
        
        if (!empty($params['language'])) {
            $whereConditions[] = "language = :language";
            $bindParams['language'] = $params['language'];
        }

        if (!empty($params['imdb_id'])) {
            $whereConditions[] = "imdb_id = :imdb_id";
            $bindParams['imdb_id'] = $params['imdb_id'];
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM series $whereClause";
        $countStmt = $this->databaseService->query($countQuery, ...$bindParams);
        $total = $countStmt->fetch()['total'];
        
        // Get paginated results
        $query = "SELECT * FROM series $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $bindParams['limit'] = $limit;
        $bindParams['offset'] = $offset;
        
        $stmt = $this->databaseService->query($query, ...$bindParams);
        $shows = $stmt->fetchAll();
        
        // Parse JSON genres for each show
        foreach ($shows as &$show) {
            if (isset($show['genres'])) {
                $decodedGenres = json_decode($show['genres'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedGenres)) {
                    $show['genres'] = $decodedGenres;
                } else {
                    // If JSON decode fails, keep as string or set to empty array
                    $show['genres'] = is_string($show['genres']) ? $show['genres'] : [];
                }
            }
        }
        
        return [
            'shows' => $shows,
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

    public function getShowLocally(mixed $id)
    {
        $query = "SELECT * FROM series WHERE id = :id";
        $st = $this->databaseService->query($query, ...$i=['id'=>$id]);
        return $st->fetch();
    }

    public function editShow(array $params, int $id): bool
    {
        try{
            $columns = array_map(function ($col) {
                return "$col = :$col";
            }, array_keys($params));

            $query = "UPDATE series SET " . implode(', ', $columns) . " WHERE id = :id";
            $params['id'] = $id;
            $st = $this->databaseService->query($query, ...$params);
            Logs::addLog($params['title'], Logs::SEASON_EDITED);
            return $st->rowCount();
        }catch (\Throwable $e){
            Message::error("Edit Show Error: ".$e->getMessage());
            return false;
        }
    }

    /**
     * @throws DatabaseException
     */
    public function deleteShow(int $id): bool
    {
        $show = $this->getShowLocally($id);
        Logs::addLog($show['title'], Logs::SHOW_DELETED);
        return $this->databaseService->query("DELETE FROM series WHERE id = :id", ...$i=['id'=>$id])->rowCount();
    }

    public function getSeasonsLocally(array $params): array
    {
        $page = (int)($params['page'] ?? 1);
        $limit = (int)($params['limit'] ?? 10);
        $offset = ($page - 1) * $limit;
        
        $whereConditions = [];
        $bindParams = [];
        
        // Build WHERE conditions based on filters
        if (!empty($params['show_id'])) {
            $whereConditions[] = "series_id = :series_id";
            $bindParams['series_id'] = $params['show_id'];
        }
        
        if (!empty($params['season_number'])) {
            $whereConditions[] = "season_number = :season_number";
            $bindParams['season_number'] = $params['season_number'];
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM seasons $whereClause";
        $countStmt = $this->databaseService->query($countQuery, ...$bindParams);
        $total = $countStmt->fetch()['total'];
        
        // Get paginated results
        $query = "SELECT * FROM seasons $whereClause ORDER BY season_number ASC, created_at DESC LIMIT :limit OFFSET :offset";
        $bindParams['limit'] = $limit;
        $bindParams['offset'] = $offset;
        
        $stmt = $this->databaseService->query($query, ...$bindParams);
        $seasons = $stmt->fetchAll();
        
        return [
            'seasons' => $seasons,
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

    public function getSeasonLocally(int $season_id)
    {
        $query = "SELECT * FROM seasons WHERE id = :season_id";
        $st = $this->databaseService->query($query, ...$i=['season_id'=>$season_id]);
        return $st->fetch();
    }

    public function deleteSeasonLocally(int $season_id): bool
    {
        try{
            $query = "DELETE FROM seasons WHERE id = :id";
            $st = $this->databaseService->query($query, ...$i=['id'=>$season_id]);
            Logs::addLog($season_id, Logs::SEASON_DELETED);
            return $st->rowCount();
        }catch (\Throwable $e){
            Message::error("Delete Season Error: ".$e->getMessage());
            return false;
        }
    }

    public function getSeason(array $params): array
    {
        if ($this->authentication->isAuthenticated()) {
            $this->http->clear();
            $paramsValidated = Http::parseParams($params, 'endpoint.show.season.detail.params');
            $this->http->setParams($paramsValidated);
            $this->http->request($this->http->getConfig()->get('endpoint.show.season.detail.path'));
            return Http::getValue($this->http->getResponseBody(), 'endpoint.show.season.detail.results');
        }
        return [];
    }

    public function createEpisode(array $params): bool|int
    {
        try{

            $query = "SELECT * FROM `episodes` WHERE imdb_id = :id";
            $st = $this->databaseService->query($query, ...$i=['id'=>$params['imdb_id']]);
            $episode = $st->fetch();
            if (empty($episode)) {
                $columns = array_keys($params);
                $placeholders = array_map(function ($col) {
                    return ":$col";
                }, $columns);
                $query = "INSERT INTO `episodes` (".implode(', ', $columns).") VALUES (".implode(', ', $placeholders).")";
                $st = $this->databaseService->query($query, ...$params);
                Logs::addLog($params['name'], Logs::EPISODE_UPLOADED);
                return $st->rowCount();
            }
            return $episode['id'];
        }catch (\Throwable $e) {
            Message::error("Create Episode Error: ".$e->getMessage());
            return false;
        }
    }

    public function getEpisodesLocally(array $params): array
    {
        $page = (int)($params['page'] ?? 1);
        $limit = (int)($params['limit'] ?? 10);
        $offset = ($page - 1) * $limit;
        
        $whereConditions = [];
        $bindParams = [];
        
        // Build WHERE conditions based on filters
        if (!empty($params['season_id'])) {
            $whereConditions[] = "season_id = :season_id";
            $bindParams['season_id'] = $params['season_id'];
        }
        
        if (!empty($params['episode_number'])) {
            $whereConditions[] = "episode_number = :episode_number";
            $bindParams['episode_number'] = $params['episode_number'];
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM episodes $whereClause";
        $countStmt = $this->databaseService->query($countQuery, ...$bindParams);
        $total = $countStmt->fetch()['total'];
        
        // Get paginated results
        $query = "SELECT * FROM episodes $whereClause ORDER BY episode_number ASC, created_at DESC LIMIT :limit OFFSET :offset";
        $bindParams['limit'] = $limit;
        $bindParams['offset'] = $offset;
        
        $stmt = $this->databaseService->query($query, ...$bindParams);
        $episodes = $stmt->fetchAll();
        
        // Parse JSON fields for each episode
        foreach ($episodes as &$episode) {
            if (isset($episode['crew'])) {
                $episode['crew'] = json_decode($episode['crew'], true) ?: [];
            }
            if (isset($episode['guest_stars'])) {
                $episode['guest_stars'] = json_decode($episode['guest_stars'], true) ?: [];
            }
        }
        
        return [
            'episodes' => $episodes,
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

    public function editEpisode(array $params, int $id)
    {
        $columns = array_map(function ($col) {
            return "$col = :$col";
        }, array_keys($params));

        $query = "UPDATE `episodes` SET ".implode(', ', $columns)." WHERE id = :id";
        $params['id'] = $id;
        $st = $this->databaseService->query($query, ...$params);
        return $st->rowCount();
    }

    public function getEpisodeLocally(int $id) {
        $query = "SELECT * FROM `episodes` WHERE id = :id";
        $episode = $this->databaseService->query($query, $id)->fetch();
        if ($episode) {
            $episode['guest_stars'] = json_decode($episode['guest_stars'], true) ?: [];
            $episode['crew'] = json_decode($episode['crew'], true) ?: [];
        }
        return $episode;
    }

    public function deleteEpisode(int $id)
    {
        $query = "DELETE FROM `episodes` WHERE id = :id";
        $st = $this->databaseService->query($query, $id);
        return $st->rowCount();
    }

    public function getTopRatedShowsLocally(int $limit = 5)
    {
        $query = "SELECT * FROM `series` ORDER BY rating DESC LIMIT :limit";
        return $this->databaseService->query($query, $limit)->fetchAll();
    }

    public function getTvShowsCountLocally()
    {
        return $this->databaseService->query("select COUNT(*) as total from series;")->fetch()['total'];
    }

    public function getRecentAddedShowsLocally(int $limit = 5)
    {
        return $this->databaseService->query("SELECT * FROM `series` ORDER BY created_at DESC LIMIT :limit",$limit)->fetchAll();
    }
}