<?php

namespace Simp\Pindrop\Modules\streamer_frontend\src\Controller;

use DateMalformedStringException;
use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Random\RandomException;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Modules\streamer\src\Plugin\Movie;
use Simp\Pindrop\Modules\streamer\src\Plugin\Playlist;
use Simp\Pindrop\Modules\streamer\src\Plugin\Show;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\Request;
use Simp\Pindrop\Database\DatabaseService;
use Symfony\Component\HttpFoundation\Response;

class FrontendController extends ControllerBase
{
    public function __construct(protected Movie $movie, protected Show $show, protected Playlist $playlist, protected DatabaseService $databaseService)
    {
        parent::__construct();
    }

    /**
     * @throws NotFoundException
     * @throws DependencyException
     */
    public static function create(Container $container): static
    {
        return new self(
            $container->get('streamer.movie'),
            $container->get('streamer.show'),
            $container->get('streamer.playlist'),
            $container->get('database')
        );
    }

    /**
     * @throws DatabaseException|DateMalformedStringException
     */
    public function homepage(Request $request, string $route_name, $options): Response
    {
        $ipAddress = $request->getClientIp();
        $randomFeatured = $this->movie->getRandomFeaturedMovie(1);
        $playingMovies = $this->movie->getMoviePlaying(12, $ipAddress);
        $playingMovies = streamer_frontend_resolve_records($playingMovies);
        $playingNow = $this->movie->getMoviePlayingNow(12);
        $playingNow = streamer_frontend_resolve_records($playingNow);
        $popularMovies = $this->movie->getMoviesLocally(['rating_min' => 6.8, 'limit' => 12])['movies'] ?? [];
        $popularShows = $this->show->getShowsLocally(['rating_min'=>6.8, 'limit'=>12])['shows'] ?? [];
        $newReleaseMovies = $this->movie->getMoviesLocally([
            'year_from'=> new \DateTime("now")->modify("-1 year")->format('Y-m-d'),
            'year_to'=> new \DateTime("now")->format('Y-m-d'),
            'limit'=>6
        ])['movies'] ?? [];
        $newReleaseShows = $this->show->getShowsLocally([
            'year_from'=> new \DateTime("now")->modify("-1 year")->format('Y-m-d'),
            'year_to'=> new \DateTime("now")->format('Y-m-d'),
            'limit'=>6
        ])['shows'] ?? [];

        if (!empty($randomFeatured)) {
            $randomFeatured = reset($randomFeatured);
            $randomFeatured['thumbnail_path'] = streamer_frontend_image_path_resolve($randomFeatured['thumbnail_path']);
            $randomFeatured['duration'] = streamer_frontend_format_minutes($randomFeatured['duration']);
        }

        foreach ($popularMovies as $k=>$popularMovie) {
            $popularMovies[$k]['thumbnail_path'] = streamer_frontend_image_path_resolve($popularMovie['thumbnail_path']);
            $popularMovies[$k]['duration'] = streamer_frontend_format_minutes($popularMovie['duration']);
        }

        foreach ($popularShows as $k=>$popularShow) {
            $popularShows[$k]['thumbnail_path'] = streamer_frontend_image_path_resolve($popularShow['thumbnail_path']);
        }

        foreach ($newReleaseMovies as $k=>$newReleaseMovie) {
            $newReleaseMovies[$k]['thumbnail_path'] = streamer_frontend_image_path_resolve($newReleaseMovie['thumbnail_path']);
        }

        foreach ($newReleaseShows as $k=>$newReleaseShow) {
            $newReleaseShows[$k]['thumbnail_path'] = streamer_frontend_image_path_resolve($newReleaseShow['thumbnail_path']);
        }

        return $this->renderTwig('@streamer_frontend/homepage.html.twig',[
            'randomFeatured' => $randomFeatured,
            'playingMovies' => $playingMovies,
            'playingNows' => $playingNow,
            'popularMovies' => $popularMovies,
            'popularShows' => $popularShows,
            'newReleaseMovies' => $newReleaseMovies,
            'newReleaseShows' => $newReleaseShows,
        ]);
    }

    /**
     * @param Request $request
     * @param string $route_name
     * @param $options
     * @return Response
     * @throws DatabaseException
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function moviesPage(Request $request, string $route_name, $options): Response
    {
        $page = (int)$request->query->get('page', 1);
        $limit = (int)$request->query->get('limit', 20);
        
        // Build filter parameters
        $params = ['page' => $page, 'limit' => $limit];
        
        // Genre filter
        $genre = $request->query->get('genre');
        if ($genre && $genre !== '') {
            $params['genre'] = $genre;
        }

        // Year filter
        $year = $request->query->get('year');
        if ($year && $year !== '') {
            $params['year_from'] = $year;
            $params['year_to'] = $year;
        }
        
        // Rating filter
        $rating = $request->query->get('rating');
        if ($rating && $rating !== '') {
            $params['rating_min'] = $rating;
        }
        
        // Search filter
        $search = $request->query->get('search');
        if ($search && $search !== '') {
            $params['title'] = $search;
        }
        
        // Sort order
        $sortBy = $request->query->get('sort', 'release_date_desc');
        
        // Get movies with filters
        $result = $this->movie->getMoviesLocally($params);
        $movies = $result['movies'] ?? [];
        $pagination = $result['pagination'] ?? [];
        
        // Apply additional sorting if needed (since getMoviesLocally only sorts by release_date DESC)
        if ($sortBy !== 'release_date_desc' && !empty($movies)) {
            switch ($sortBy) {
                case 'title_asc':
                    usort($movies, function($a, $b) {
                        return strcmp($a['title'] ?? '', $b['title'] ?? '');
                    });
                    break;
                case 'rating_desc':
                    usort($movies, function($a, $b) {
                        return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
                    });
                    break;
                case 'release_date_asc':
                    usort($movies, function($a, $b) {
                        return strcmp($a['release_date'] ?? '', $b['release_date'] ?? '');
                    });
                    break;
            }
        }
        
        // Process movie data for template
        foreach ($movies as $k => $movie) {
            $movies[$k]['thumbnail_path'] = streamer_frontend_image_path_resolve($movie['thumbnail_path']);
            $movies[$k]['duration'] = streamer_frontend_format_minutes($movie['duration']);
            
            // Ensure genres is an array and extract genre values
            if (is_string($movies[$k]['genres'])) {
                $decoded = json_decode($movies[$k]['genres'], true);
                $movies[$k]['genres'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
            }
            
            // Extract genre values if genres have nested structure
            if (is_array($movies[$k]['genres'])) {
                $genreValues = [];
                foreach ($movies[$k]['genres'] as $genre_item) {
                    if (is_array($genre) && isset($genre_item['value'])) {
                        $genreValues[] = $genre_item['value'];
                    } elseif (is_string($genre_item)) {
                        $genreValues[] = $genre_item;
                    }
                }
                $movies[$k]['genres'] = $genreValues;
            }
        }
        
        // Get available genres for filter dropdown
        $genresResult = $this->movie->getGenres([]);
        $availableGenres = [];
        if ($genresResult) {
            foreach ($genresResult as $genre_item) {
                $availableGenres[] = $genre_item['name'];
            }
        }

        // Get available years for filter dropdown
        $yearsQuery = "SELECT DISTINCT YEAR(release_date) as year FROM movies WHERE release_date IS NOT NULL ORDER BY year DESC";
        $yearsStmt = $this->databaseService->query($yearsQuery);
        $availableYears = $yearsStmt->fetchAll(\PDO::FETCH_COLUMN);

        return $this->renderTwig('@streamer_frontend/movies-listing.html.twig', [
            'movies' => $movies,
            'pagination' => $pagination,
            'filters' => [
                'genre' => (string)$genre,
                'year' => (string)$year,
                'rating' => (string)$rating,
                'search' => (string)$search,
                'sort' => (string)$sortBy
            ],
            'availableGenres' => $availableGenres,
            'availableYears' => $availableYears,
            'currentPage' => $page,
            'hasNext' => $pagination['has_next'] ?? false,
            'total' => $pagination['total'] ?? 0
        ]);

    }

    /**
     * @param Request $request
     * @param string $route_name
     * @param $options
     * @return Response
     * @throws DatabaseException
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function moviePage(Request $request, string $route_name, $options): Response
    {
        $movieId = $request->query->get('movie_id');
        
        if (!$movieId) {
            return $this->redirect("/");
        }

        if ($request->isMethod('POST')) {
            $playlistId = $request->request->get('item');
            $name = $request->request->get('name');
            $description = $request->request->get('description');
            $isPublished = !empty($request->request->get('is_public'));

           if (!empty($playlistId)) {
               $this->playlist->addPlaylistItem((int)$playlistId, $movieId);
               return $this->redirect(Url::routeByName('streamer_frontend.front.movie.detail',['movie_id' => $movieId]));
           }
           else {
               if ($id = $this->playlist->createPlaylist($request->getClientIp(), $name, $description,$isPublished)) {
                   $this->playlist->addPlaylistItem($id, $movieId);
                   return $this->redirect(Url::routeByName('streamer_frontend.front.movie.detail',['movie_id' => $movieId]));
               }
           }
        }
        
        // Get movie data
        $movie = $this->movie->getMovieLocally($movieId);
        $tmdbMovie = $this->movie->getMovie(['movie_id' => $movie['imdb_id']], true);

        // Process movie data for template
        $movie['thumbnail_path'] = streamer_frontend_image_path_resolve($movie['thumbnail_path']);
        $movie['duration'] = streamer_frontend_format_minutes($movie['duration']);
        $movie['genres'] = json_decode($movie['genres'], true);
        
        // Process genres to extract values
        $genreValues = [];
        foreach ($movie['genres'] as $genre) {
            if (is_array($genre) && isset($genre['value'])) {
                $genreValues[] = $genre['value'];
            } elseif (is_string($genre)) {
                $genreValues[] = $genre;
            }
        }
        $movie['genres'] = $genreValues;

        $playlists = $this->playlist->getPlaylists(['session_token' => $request->getClientIp(), 'limit' => 200])['playlists'];

        return $this->renderTwig('@streamer_frontend/movie-detail.html.twig', [
            'movie' => $movie,
            'playlists' => $playlists,
            'tmdbMovie' => $tmdbMovie,
        ]);
    }

    public function showsPage(Request $request, string $route_name, $options): Response
    {
        $page = (int)$request->query->get('page', 1);
        $limit = (int)$request->query->get('limit', 20);
        
        // Build filter parameters
        $params = ['page' => $page, 'limit' => $limit];
        
        // Genre filter
        $genre = $request->query->get('genre');
        if ($genre && $genre !== '') {
            $params['genre'] = $genre;
        }
        
        // Year filter
        $year = $request->query->get('year');
        if ($year && $year !== '') {
            $params['year_from'] = $year;
            $params['year_to'] = $year;
        }
        
        // Rating filter
        $rating = $request->query->get('rating');
        if ($rating && $rating !== '') {
            $params['rating_min'] = $rating;
        }
        
        // Search filter
        $search = $request->query->get('search');
        if ($search && $search !== '') {
            $params['title'] = $search;
        }
        
        // Sort order
        $sortBy = $request->query->get('sort', 'start_year_desc');

        // Get shows with filters
        $result = $this->show->getShowsLocally($params);
        $shows = $result['shows'] ?? [];
        $pagination = $result['pagination'] ?? [];
        
        // Apply additional sorting if needed
        if ($sortBy !== 'start_year_desc' && !empty($shows)) {
            switch ($sortBy) {
                case 'title_asc':
                    usort($shows, function($a, $b) {
                        return strcmp($a['title'] ?? '', $b['title'] ?? '');
                    });
                    break;
                case 'rating_desc':
                    usort($shows, function($a, $b) {
                        return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
                    });
                    break;
                case 'start_year_asc':
                    usort($shows, function($a, $b) {
                        return ($a['start_year'] ?? 0) <=> ($b['start_year'] ?? 0);
                    });
                    break;
            }
        }
        
        // Process show data for template
        foreach ($shows as $k => $show) {
           if (!empty($show['thumbnail_path'])) {
               $shows[$k]['thumbnail_path'] = streamer_frontend_image_path_resolve($show['thumbnail_path']);
           }

            
            // Ensure genres is an array and extract genre values
            if (is_string($shows[$k]['genres'])) {
                $decoded = json_decode($shows[$k]['genres'], true);
                $shows[$k]['genres'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
            }
            
            // Extract genre values if genres have nested structure
            if (is_array($shows[$k]['genres'])) {
                $genreValues = [];
                foreach ($shows[$k]['genres'] as $genre_item) {
                    if (is_array($genre_item) && isset($genre_item['value'])) {
                        $genreValues[] = $genre_item['value'];
                    } elseif (is_string($genre_item)) {
                        $genreValues[] = $genre_item;
                    }
                }
                $shows[$k]['genres'] = $genreValues;
            }
        }
        
        // Get available genres for filter dropdown (reuse from movies)
        $genresResult = $this->movie->getGenres([]);
        $availableGenres = [];
        if (isset($genresResult) && is_array($genresResult)) {
            foreach ($genresResult as $genre_item) {
                if (isset($genre_item['name'])) {
                    $availableGenres[] = $genre_item['name'];
                }
            }
        }
        
        // Get available years for filter dropdown
        $yearsQuery = "SELECT DISTINCT start_year FROM series WHERE start_year IS NOT NULL ORDER BY start_year DESC";
        $yearsStmt = $this->databaseService->query($yearsQuery);
        $availableYears = $yearsStmt->fetchAll(\PDO::FETCH_COLUMN);

        return $this->renderTwig('@streamer_frontend/shows-listing.html.twig', [
            'shows' => $shows,
            'pagination' => $pagination,
            'filters' => [
                'genre' => (string)$genre,
                'year' => (string)$year,
                'rating' => (string)$rating,
                'search' => (string)$search,
                'sort' => (string)$sortBy
            ],
            'availableGenres' => $availableGenres,
            'availableYears' => $availableYears,
            'currentPage' => $page,
            'hasNext' => $pagination['has_next'] ?? false,
            'total' => $pagination['total'] ?? 0
        ]);
    }

    public function showPage(Request $request, string $route_name, $options): string
    {
        $showId = (int)$request->query->get('show_id');
        if (empty($showId)) {
            return $this->redirect('/');
        }

        $show = $this->show->getShowLocally($showId);
        $tmdbShow = $this->show->getShow(['series_id' => $show['imdb_id']], true);
        $seasons = $this->show->getSeasonsLocally(['series_id' => $show['id']])['seasons'] ?? [];


        return $this->renderTwig("@streamer_frontend/show-detail.html.twig", [
            'show' => $show,
            'tmdbShow' => $tmdbShow,
            'seasons' => $seasons,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function playlistsPage(Request $request, string $route_name, $options): string
    {
        $playlists = $this->playlist->getPlaylists(['limit' => 100])['playlists'] ?? [];

        if ($request->isMethod('POST')) {

            $name = $request->request->get('name');
            $description = $request->request->get('description');
            $public = !empty($request->request->get('public'));


            if (!empty($name)) {
                $this->playlist->createPlaylist($request->getClientIp(), $name, $description, $public);
            }

            return $this->redirect(Url::routeByName('streamer_frontend.front.playlists'));
        }

        foreach ($playlists as &$playlist) {
            $items = $this->playlist->getPlaylistItems(pid: $playlist['id']);
            foreach ($items as &$item) {
                $movie = $this->movie->getMovieLocally($item['video_id']);
                $movie['thumbnail_path'] = streamer_frontend_image_path_resolve($movie['thumbnail_path']);
                $item['movie'] = $movie;
            }
            $playlist['items'] = $items;
        }

        return $this->renderTwig("@streamer_frontend/playlists.html.twig", [
            'playlists' => $playlists,
        ]);
    }

    public function playlistPage(Request $request, string $route_name, $options): string
    {
        $id = (int)$request->query->get('id');
        $playlist = $this->playlist->getPlaylist(pid: $id);
        $playlistItems = $this->playlist->getPlaylistItems(pid: $id);

        $playlistItemMovies = [];
        $duration = 0;
        $images = [];
        $detail = [
            'detail' => $playlist,
        ];

        foreach ($playlistItems as $playlistItem) {
            $movie = $this->movie->getMovieLocally($playlistItem['video_id']);
            $movie['thumbnail_path'] = streamer_frontend_image_path_resolve($movie['thumbnail_path']);
            $duration += (int) $movie['duration'];
            $playlistItemMovies[] = $movie;
            $images[] = $movie['thumbnail_path'];
        }

        $detail['images'] = $images;
        $detail['duration'] = streamer_frontend_format_minutes($duration);
        $detail['movies'] = $playlistItemMovies;

        return $this->renderTwig("@streamer_frontend/playlist-detail.html.twig", [
            'playlist' => $detail,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function deletePlaylistPage(Request $request, string $route_name, $options)
    {
        $id = $request->query->get('id');
        $this->playlist->deletePlaylist($id);
        return $this->redirect(Url::routeByName('streamer_frontend.front.playlists'));
    }
}