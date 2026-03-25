<?php

namespace Simp\Pindrop\Modules\streamer\src\Controller;

use DI\Container;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\streamer\src\Plugin\Movie;
use Simp\Pindrop\Modules\streamer\src\Plugin\Show;
use Simp\Pindrop\Modules\streamer\src\Plugin\Playlist;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiController extends ControllerBase
{
    public function __construct(
        protected Movie $movie,
        protected Show $show,
        protected Playlist $playlist
    ) {
        parent::__construct();
    }

    public static function create(Container $container): ApiController
    {
        return new self(
            $container->get('streamer.movie'),
            $container->get('streamer.show'),
            $container->get('streamer.playlist')
        );
    }

    /**
     * API endpoint for featured movies
     * GET /api/movies/featured
     */
    public function featuredMovies(Request $request, string $route_name, array $options): JsonResponse
    {
        $movies = $this->movie->getMoviesLocally(['featured' => 1, 'limit' => 10]);
        
        $formattedMovies = array_map(function($movie) {
            $genres = [];
            if (is_string($movie['genres'])) {
                $decoded = json_decode($movie['genres'], true);
                if (is_array($decoded)) {
                    $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
                }
            } elseif (is_array($movie['genres'])) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $movie['genres']);
            }
            
            return [
                'id' => 'movie-' . $movie['id'],
                'title' => $movie['title'],
                'description' => $movie['description'] ?? '',
                'posterUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'backdropUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'rating' => (float)($movie['rating'] ?? 0),
                'releaseYear' => (int)date('Y', strtotime($movie['release_date'] ?? 'now')),
                'genres' => $genres,
                'duration' => (int)($movie['duration'] ?? 0),
                'featured' => true,
                'trending' => false
            ];
        }, $movies['movies']);

        return new JsonResponse($formattedMovies);
    }

    /**
     * API endpoint for trending movies
     * GET /api/movies/trending
     */
    public function trendingMovies(Request $request, string $route_name, array $options): JsonResponse
    {
        $movies = $this->movie->getMoviesLocally(['limit' => 10]);
        
        $formattedMovies = array_map(function($movie) {
            $genres = [];
            if (is_string($movie['genres'])) {
                $decoded = json_decode($movie['genres'], true);
                if (is_array($decoded)) {
                    $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
                }
            } elseif (is_array($movie['genres'])) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $movie['genres']);
            }
            
            return [
                'id' => 'movie-' . $movie['id'],
                'title' => $movie['title'],
                'description' => $movie['description'] ?? '',
                'posterUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'backdropUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'rating' => (float)($movie['rating'] ?? 0),
                'releaseYear' => (int)date('Y', strtotime($movie['release_date'] ?? 'now')),
                'genres' => $genres,
                'duration' => (int)($movie['duration'] ?? 0),
                'featured' => false,
                'trending' => true
            ];
        }, $movies['movies']);

        return new JsonResponse($formattedMovies);
    }

    /**
     * API endpoint for popular TV shows
     * GET /api/shows/popular
     */
    public function popularTVShows(Request $request, string $route_name, array $options): JsonResponse
    {
        $shows = $this->show->getShowsLocally(['limit' => 10]);
        
        $formattedShows = array_map(function($show) {
            $genres = [];
            if (is_string($show['genres'])) {
                $decoded = json_decode($show['genres'], true);
                if (is_array($decoded)) {
                    $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
                }
            } elseif (is_array($show['genres'])) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $show['genres']);
            }
            
            // Get episodes for this show
            $episodesResult = $this->show->getEpisodesLocally(['season_id' => $show['id']]);
            $episodes = array_map(function($episode) {
                return [
                    'id' => 'ep-' . $episode['id'],
                    'title' => $episode['name'],
                    'description' => $episode['overview'] ?? '',
                    'episodeNumber' => (int)$episode['episode_number'],
                    'seasonNumber' => (int)$episode['season_number'],
                    'duration' => (int)($episode['runtime'] ?? 0),
                    'releaseDate' => $episode['air_date'] ? date('Y-m-d', strtotime($episode['air_date'])) : '',
                    'thumbnailUrl' => $this->getPosterUrl($episode['still_path'] ?? ''),
                    'videoUrl' => '' // TODO: Add video URL logic
                ];
            }, $episodesResult['episodes'] ?? []);
            
            return [
                'id' => 'show-' . $show['id'],
                'title' => $show['title'],
                'description' => $show['description'] ?? '',
                'posterUrl' => $this->getPosterUrl($show['poster'] ?? $show['thumbnail_path'] ?? ''),
                'backdropUrl' => $this->getPosterUrl($show['poster'] ?? $show['thumbnail_path'] ?? ''),
                'rating' => (float)($show['rating'] ?? 0),
                'releaseYear' => (int)($show['start_year'] ?? date('Y')),
                'genres' => $genres,
                'seasons' => (int)($show['season_count'] ?? 0),
                'episodes' => array_slice($episodes, 0, 2), // Limit episodes for performance
                'featured' => true,
                'trending' => true
            ];
        }, $shows['shows']);

        return new JsonResponse($formattedShows);
    }

    /**
     * API endpoint for all playlists
     * GET /api/playlists
     */
    public function playlists(Request $request, string $route_name, array $options): JsonResponse
    {
        $playlistsResult = $this->playlist->getPlaylists(['limit' => 20]);
        $formattedPlaylists = array_map(function($playlist) {
            // Get playlist items
            $items = [];
            if (!empty($playlist['session_token'])) {
                $playlistItems = $this->playlist->getPlaylistItems($playlist['session_token']);
                $items = array_map(function($item) {
                    // Try to get movie/episode details
                    $type = 'movie';
                    $title = 'Unknown Item';
                    $description = '';
                    $duration = 0;
                    $thumbnailUrl = '';
                    $videoUrl = '';
                    
                    // This is a simplified version - you'd need to join with movies/episodes tables
                    return [
                        'id' => 'item-' . $item['id'],
                        'title' => $title,
                        'description' => $description,
                        'duration' => $duration,
                        'thumbnailUrl' => $thumbnailUrl,
                        'videoUrl' => $videoUrl,
                        'type' => $type
                    ];
                }, $playlistItems);
            }
            
            return [
                'id' => 'playlist-' . $playlist['id'],
                'title' => $playlist['title'],
                'description' => $playlist['description'] ?? '',
                'coverUrl' => 'https://images.unsplash.com/photo-1518676590629-3dcbd9c5a5c9?w=400&h=400&fit=crop',
                'itemCount' => (int)($playlist['item_count'] ?? 0),
                'items' => $items
            ];
        }, $playlistsResult['playlists']);

        return new JsonResponse($formattedPlaylists);
    }

    /**
     * API endpoint for recently added movies
     * GET /api/movies/recent
     */
    public function recentlyAdded(Request $request, string $route_name, array $options): JsonResponse
    {
        $movies = $this->movie->getMoviesLocally(['limit' => 10, 'order' => 'created_at DESC']);
        
        $formattedMovies = array_map(function($movie) {
            $genres = [];
            if (is_string($movie['genres'])) {
                $decoded = json_decode($movie['genres'], true);
                if (is_array($decoded)) {
                    $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
                }
            } elseif (is_array($movie['genres'])) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $movie['genres']);
            }
            
            return [
                'id' => 'movie-' . $movie['id'],
                'title' => $movie['title'],
                'description' => $movie['description'] ?? '',
                'posterUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'backdropUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'rating' => (float)($movie['rating'] ?? 0),
                'releaseYear' => (int)date('Y', strtotime($movie['release_date'] ?? 'now')),
                'genres' => $genres,
                'duration' => (int)($movie['duration'] ?? 0)
            ];
        }, $movies['movies']);

        return new JsonResponse($formattedMovies);
    }

    /**
     * API endpoint for recommended movies
     * GET /api/movies/recommended
     */
    public function recommended(Request $request, string $route_name, array $options): JsonResponse
    {
        $movies = $this->movie->getMoviesLocally(['rating_min' => 8.0, 'limit' => 10]);
        
        $formattedMovies = array_map(function($movie) {
            $genres = [];
            if (is_string($movie['genres'])) {
                $decoded = json_decode($movie['genres'], true);
                if (is_array($decoded)) {
                    $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
                }
            } elseif (is_array($movie['genres'])) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $movie['genres']);
            }
            
            return [
                'id' => 'movie-' . $movie['id'],
                'title' => $movie['title'],
                'description' => $movie['description'] ?? '',
                'posterUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'backdropUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'rating' => (float)($movie['rating'] ?? 0),
                'releaseYear' => (int)date('Y', strtotime($movie['release_date'] ?? 'now')),
                'genres' => $genres,
                'duration' => (int)($movie['duration'] ?? 0)
            ];
        }, $movies['movies']);

        return new JsonResponse($formattedMovies);
    }

    /**
     * API endpoint for all movies (browse)
     * GET /api/movies
     */
    public function allMovies(Request $request, string $route_name, array $options): JsonResponse
    {
        $filters = [
            'page' => $request->query->get('page', 1),
            'limit' => $request->query->get('limit', 20)
        ];
        
        if ($request->query->get('genre')) {
            $filters['genre'] = $request->query->get('genre');
        }
        
        if ($request->query->get('search')) {
            $filters['search'] = $request->query->get('search');
        }
        
        $moviesResult = $this->movie->getMoviesLocally($filters);
        
        $formattedMovies = array_map(function($movie) {
            $genres = [];
            if (is_string($movie['genres'])) {
                $decoded = json_decode($movie['genres'], true);
                if (is_array($decoded)) {
                    $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
                }
            } elseif (is_array($movie['genres'])) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $movie['genres']);
            }
            
            return [
                'id' => 'movie-' . $movie['id'],
                'title' => $movie['title'],
                'description' => $movie['description'] ?? '',
                'posterUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'backdropUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'rating' => (float)($movie['rating'] ?? 0),
                'releaseYear' => (int)date('Y', strtotime($movie['release_date'] ?? 'now')),
                'genres' => $genres,
                'duration' => (int)($movie['duration'] ?? 0)
            ];
        }, $moviesResult['movies']);

        return new JsonResponse([
            'movies' => $formattedMovies,
            'pagination' => $moviesResult['pagination']
        ]);
    }

    /**
     * API endpoint for all TV shows (browse)
        ];
    }, $showsResult['shows']);
            if (is_string($show['genres'])) {
                $decoded = json_decode($show['genres'], true);
                if (is_array($decoded)) {
                    $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
                }
            } elseif (is_array($show['genres'])) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $show['genres']);
            }
            
            return [
                'id' => 'show-' . $show['id'],
                'title' => $show['title'],
                'description' => $show['description'] ?? '',
                'posterUrl' => $this->getPosterUrl($show['poster'] ?? $show['thumbnail_path'] ?? ''),
                'backdropUrl' => $this->getPosterUrl($show['poster'] ?? $show['thumbnail_path'] ?? ''),
                'rating' => (float)($show['rating'] ?? 0),
                'releaseYear' => (int)($show['start_year'] ?? date('Y')),
                'genres' => $genres,
                'seasons' => (int)($show['season_count'] ?? 0),
                'episodes' => [] // Empty for browse view, populated on detail view
            ];
        }, $showsResult['shows']);

    return new JsonResponse([
        'shows' => $formattedShows,
        'pagination' => $showsResult['pagination']
    ]);
}

/**
 * API endpoint for all TV shows (browse)
 * GET /api/shows
 */
public function allTVShows(Request $request, string $route_name, array $options): JsonResponse
{
    $filters = [
        'page' => $request->query->get('page', 1),
        'limit' => $request->query->get('limit', 20)
    ];
    
    if ($request->query->get('genre')) {
        $filters['genre'] = $request->query->get('genre');
    }
    
    if ($request->query->get('search')) {
        $filters['search'] = $request->query->get('search');
    }
    
    $showsResult = $this->show->getShowsLocally($filters);
    
    $formattedShows = array_map(function($show) {
        $genres = [];
        if (is_string($show['genres'])) {
            $decoded = json_decode($show['genres'], true);
            if (is_array($decoded)) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
            }
        } elseif (is_array($show['genres'])) {
            $genres = array_map(fn($g) => $g['value'] ?? $g, $show['genres']);
        }
        
        return [
            'id' => 'show-' . $show['id'],
            'title' => $show['title'],
            'description' => $show['description'] ?? '',
            'posterUrl' => $this->getPosterUrl($show['poster'] ?? $show['thumbnail_path'] ?? ''),
            'backdropUrl' => $this->getPosterUrl($show['poster'] ?? $show['thumbnail_path'] ?? ''),
            'rating' => (float)($show['rating'] ?? 0),
            'releaseYear' => (int)date('Y', strtotime($show['release_date'] ?? 'now')),
            'genres' => $genres,
            'seasons' => (int)($show['season_count'] ?? 1),
            'episodes' => []
        ];
    }, $showsResult['shows']);

    return new JsonResponse([
        'shows' => $formattedShows,
        'pagination' => $showsResult['pagination']
    ]);
}

/**
 * API endpoint for genres
 * GET /api/genres
 */
public function genres(Request $request, string $route_name, array $options): JsonResponse
{
    $genres = [
        'Action', 'Adventure', 'Animation', 'Comedy', 'Crime',
        'Documentary', 'Drama', 'Family', 'Fantasy', 'History',
        'Horror', 'Mystery', 'Romance', 'Sci-Fi', 'Thriller', 'War'
    ];

    return new JsonResponse($genres);
}

    /**
     * API endpoint for search
     * GET /api/search?q=query
     */
    public function search(Request $request, string $route_name, array $options): JsonResponse
    {
        $query = $request->query->get('q', '');
        
        if (empty($query)) {
            return new JsonResponse(['movies' => [], 'shows' => [], 'playlists' => []]);
        }

        // Search movies
        $moviesResult = $this->movie->getMoviesLocally(['title' => $query, 'limit' => 10]);
        $movies = array_map(function($movie) {
            $genres = [];
            if (is_string($movie['genres'])) {
                $decoded = json_decode($movie['genres'], true);
                if (is_array($decoded)) {
                    $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
                }
            } elseif (is_array($movie['genres'])) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $movie['genres']);
            }
            
            return [
                'id' => 'movie-' . $movie['id'],
                'title' => $movie['title'],
                'description' => $movie['description'] ?? '',
                'posterUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'backdropUrl' => $this->getPosterUrl($movie['poster'] ?? $movie['thumbnail_path'] ?? ''),
                'rating' => (float)($movie['rating'] ?? 0),
                'releaseYear' => (int)date('Y', strtotime($movie['release_date'] ?? 'now')),
                'genres' => $genres,
                'duration' => (int)($movie['duration'] ?? 0)
            ];
        }, $moviesResult['movies']);

        // Search shows
        $showsResult = $this->show->getShowsLocally(['title' => $query, 'limit' => 10]);
        $shows = array_map(function($show) {
            $genres = [];
            if (is_string($show['genres'])) {
                $decoded = json_decode($show['genres'], true);
                if (is_array($decoded)) {
                    $genres = array_map(fn($g) => $g['value'] ?? $g, $decoded);
                }
            } elseif (is_array($show['genres'])) {
                $genres = array_map(fn($g) => $g['value'] ?? $g, $show['genres']);
            }
            
            return [
                'id' => 'show-' . $show['id'],
                'title' => $show['title'],
                'description' => $show['description'] ?? '',
                'posterUrl' => $this->getPosterUrl($show['poster'] ?? $show['thumbnail_path'] ?? ''),
                'backdropUrl' => $this->getPosterUrl($show['poster'] ?? $show['thumbnail_path'] ?? ''),
                'rating' => (float)($show['rating'] ?? 0),
                'releaseYear' => (int)($show['start_year'] ?? date('Y')),
                'genres' => $genres,
                'seasons' => (int)($show['season_count'] ?? 0),
                'episodes' => []
            ];
        }, $showsResult['shows']);

        return new JsonResponse([
            'movies' => $movies,
            'shows' => $shows,
            'playlists' => []
        ]);
    }

    /**
     * Helper method to get poster URL
     */
    private function getPosterUrl(string $posterPath): string
    {
        if (empty($posterPath)) {
            return 'https://images.unsplash.com/photo-1485846234645-a62644f84728?w=400&h=600&fit=crop';
        }

        if (str_starts_with($posterPath, 'public://')) {
            return '/sites/default/' . str_replace('public://', '', $posterPath);
        }

        if (str_starts_with($posterPath, 'http')) {
            return $posterPath;
        }

        // Assume TMDB path
        return 'https://image.tmdb.org/t/p/w185' . $posterPath;
    }
}