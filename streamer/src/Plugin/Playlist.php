<?php

namespace Simp\Pindrop\Modules\streamer\src\Plugin;

use Random\RandomException;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Symfony\Component\HttpFoundation\Request;

class Playlist
{
    public function __construct(protected DatabaseService $databaseService)
    {
    }

    /**
     * @throws DatabaseException
     */
    public function createPlaylist(string $session, string $title, string $description = "", bool $is_public = false): int
    {
        $validated = [
            'title' => $title,
            'description' => $description,
            'is_public' => $is_public ? 1 : 0,
            'session_token' => $session,
        ];
        return $this->databaseService->insert('playlists', $validated);
    }

    /**
     * @throws DatabaseException
     */
    public function getPlaylist(?string $session_token = null, ?int $pid = null): array|bool
    {
        if (!empty($session_token)) {
            return $this->databaseService->query("SELECT * FROM playlists WHERE session_token = :session_token LIMIT 1", ...$o = ['session_token' => $session_token])
                ->fetch();
        }

        if (!empty($pid)) {
            return $this->databaseService->query("SELECT * FROM playlists WHERE id = :id LIMIT 1", $pid)
                ->fetch();
        }
        return [];
    }

    /**
     * @throws DatabaseException
     */
    public function addPlaylistItem($session_token, int $video_id): false|int
    {
        $playlist = [];
        if (is_string($session_token)) {
            $playlist = $this->getPlaylist($session_token);
        }
        elseif (is_numeric($session_token)) {
            $playlist = $this->getPlaylist(pid: $session_token);
        }
        if (empty($playlist)) {
            return false;
        }

        $validated = [
            'video_id' => $video_id,
            'playlist_id' => $playlist['id'],
            'position' => count($this->getPlaylistItems(pid: $playlist['id'])) + 1,
        ];

        return $this->databaseService->insert('playlist_items', $validated);
    }

    /**
     * @throws DatabaseException
     */
    public function getPlaylistItems(?string $session_token = null, ?int $pid = null): array
    {
        if ($pid) {
            $query = "SELECT * FROM playlist_items WHERE playlist_id = :pid";
            return $this->databaseService->query($query, $pid)->fetchAll();
        }

        if (!empty($session_token)) {
            $playlist = $this->getPlaylist($session_token);
            if (!empty($playlist)) {
                $pid = $playlist['playlist_id'];
                $query = "SELECT * FROM playlist_items WHERE playlist_id = :pid";
                return $this->databaseService->query($query, $pid)->fetchAll();
            }
        }
        return [];
    }

    /**
     * @throws DatabaseException
     */
    public function removePlaylistItem(int $id): bool {
        return $this->databaseService->query("DELETE FROM playlist_items WHERE id = :id", $id)->rowCount();
    }

    /**
     * @throws DatabaseException
     */
    public function deletePlaylist(int $id): bool
    {
        return $this->databaseService->query("DELETE FROM playlists WHERE id = :id", $id)->rowCount();
    }

    public function getPlaylists(array $params = []): array
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
        
        if (isset($params['is_public'])) {
            $whereConditions[] = "is_public = :is_public";
            $bindParams['is_public'] = $params['is_public'] ? 1 : 0;
        }

        if (isset($params['session_token'])) {
            $whereConditions[] = "session_token = :session_token";
            $bindParams['session_token'] = $params['session_token'];
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM playlists $whereClause";
        $countStmt = $this->databaseService->query($countQuery, ...$bindParams);
        $total = $countStmt->fetch()['total'];
        
        // Get paginated results
        $query = "SELECT * FROM playlists $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $bindParams['limit'] = $limit;
        $bindParams['offset'] = $offset;
        
        $stmt = $this->databaseService->query($query, ...$bindParams);
        $playlists = $stmt->fetchAll();
        
        // Get item count for each playlist
        foreach ($playlists as &$playlist) {
            $itemCountQuery = "SELECT COUNT(*) as item_count FROM playlist_items WHERE playlist_id = :playlist_id";
            $itemCountStmt = $this->databaseService->query($itemCountQuery, $playlist['id']);
            $playlist['item_count'] = $itemCountStmt->fetch()['item_count'];
        }
        
        return [
            'playlists' => $playlists,
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
     * @throws DatabaseException
     */
    public function getMovie(array $params = []): array
    {
        $whereConditions = [];
        $bindParams = [];

        if (!empty($params['ratingFilter'])) {
            $whereConditions[] = "rating >= :rating";
            $bindParams['rating'] = $params['ratingFilter'];
        }

        if (!empty($params['includeFeatured'])) {
            $whereConditions[] = "featured IS NOT NULL";
        }

        if (!empty($params['yearRange'])) {
            $whereConditions[] = "release_date BETWEEN :year_from AND :year_to";
            $bindParams['year_from'] = $params['yearRange'][0];
            $bindParams['year_to'] = !empty($params['yearRange'][1]) ? $params['yearRange'][1] : $params['yearRange'][0];
        }

        if (!empty($params['genreFilter'])) {
            // column genres is json type
            $whereConditions[] = "JSON_CONTAINS(genres, :genre)";
            $bindParams['genre'] = json_encode(['value' => $params['genreFilter']]);
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        $query = "SELECT * FROM movies $whereClause ORDER BY release_date DESC";

        if (!empty($params['maxMovies'])) {
            $query .= " LIMIT :limit";
            $bindParams['limit'] = (int) $params['maxMovies'];
        }

        return $this->databaseService->query($query, ...$bindParams)->fetchAll();
    }

    public function getPlaylistCount()
    {
        return $this->databaseService->query("SELECT COUNT(*) as total FROM playlists")->fetch()['total'] ?? 0;
    }

    public function getRecentPlaylists(int $limit = 5): array
    {
        return $this->databaseService->query("SELECT * FROM playlists ORDER BY created_at DESC LIMIT :limit", $limit)->fetchAll();
    }

}