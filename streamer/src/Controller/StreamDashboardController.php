<?php

namespace Simp\Pindrop\Modules\streamer\src\Controller;

use DI\DependencyException;
use DI\NotFoundException;
use FilesystemIterator;
use Psr\Container\ContainerInterface;
use Random\RandomException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Entity\File\File;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\FileSystem\FileSystem;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\streamer\src\Plugin\Movie;
use Simp\Pindrop\Modules\streamer\src\Plugin\Playlist;
use Simp\Pindrop\Modules\streamer\src\Plugin\Show;
use Simp\Pindrop\Modules\streamer\src\Services\Logs;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StreamDashboardController extends ControllerBase
{

    public function __construct(protected Movie $movie, protected Show $show, protected Playlist $playlist)
    {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): StreamDashboardController
    {
        return new self(
            $container->get('streamer.movie'),
            $container->get('streamer.show'),
            $container->get('streamer.playlist')
        );
    }

    function getDirectorySize(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $size = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }

        } catch (Throwable $e) {
            // Optional: log error
            // error_log($e->getMessage());
            return $size;
        }

        return $size;
    }

    function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    function calculatePercentage(int|float $used, int|float $total, int $precision = 2): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($used / $total) * 100, $precision);
    }

    public function dashboard(Request $request, string $route_name, array $options): Response
    {
        $totalDisk = disk_total_space('sites');
        $used  = $this->getDirectorySize("public://");

        $dashboard = [
            'movies_count' => $this->movie->getMoviesCountLocally(),
            'tv_shows_count' => $this->show->getTvShowsCountLocally(),
            'recent_movies' => $this->movie->getRecentAddedMoviesLocally(5),
            'recent_shows'  => $this->show->getRecentAddedShowsLocally(5),
            'top_rated_movies' => $this->movie->getTopRatedMoviesLocally(2),
            'top_rated_tvs'  => $this->show->getTopRatedShowsLocally(2),
            'recent_activities' => Logs::getRecentLogs(3),
            'occupied_space'    => $this->formatBytes($used),
            'total_space'        => $this->formatBytes($totalDisk),
            'percent_used'       => $this->calculatePercentage( $used, $totalDisk),
            'playlist_count'     => $this->playlist->getPlaylistCount(),
            'playlists'          => $this->playlist->getPlaylists(['limit' => 5])['playlists'] ?? []
        ];
      
        return $this->renderTwig("@streamer/dashboard.html.twig", $dashboard);
    }

    public function createMovie(Request $request, string $route_name, array $options): Response
    {
        $validated = [];
        if ($request->isMethod(Request::METHOD_POST)) {
            $video = $request->request->all();
            $video['title'] = substr($video['title'],0, strrpos($video['title'],"-")+1);
            $video['title'] = trim($video['title'], '-');

            if (!empty($video['videoFileId'])) {
                $validated['video_file_id'] = $video['videoFileId'];
                $file = File::load($video['videoFileId']);
                if ($file instanceof File) {
                    $validated['video_path'] = $file->getUri();
                    unset($video['videoFileId']);
                    unset($video['thumbnail']);
                    unset($video['videoFile']);
                }
                $validated['featured'] = !empty($video['featured']);
                unset($video['featured']);

                if (!empty($video['releaseYear'])) {
                    $validated['release_date'] = $video['releaseYear'];
                    unset($video['releaseYear']);
                }

                $validated = [
                    ...$validated,
                    ...$video
                ];

                $validated['featured'] = !empty($validated['featured']) ? 1 : 0;

                if ($this->movie->createMovie($validated)) {
                    Message::info("Movie created with title @title", ["@title" => $validated['title']]);
                    return $this->redirect(Url::routeByName('streamer.dashboard.movies'));
                }

            }
        }

        if (!empty($validated['video_file_id'])) {
            $file = File::load($validated['video_file_id']);
            if ($file instanceof File) {
                $validated['public_uri'] = "/sites/default". $file->getPublicUrl();
            }
        }

        if (!empty($validated['thumbnail_path'])) {

            if (str_starts_with($validated['thumbnail_path'], 'public://')) {
                $validated['thumbnail_uri'] = str_replace('public://', '/sites/default/', $validated['thumbnail_path']);
            }
            else {
                $validated['thumbnail_uri'] = "http://image.tmdb.org/t/p/w185". $validated['thumbnail_path'];
            }

        }

        return $this->renderTwig("@streamer/movie-create.html.twig", $validated);
    }

    public function editMovie(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('movie_id');
        if (empty($id)) {
            return $this->redirect(Url::routeByName('streamer.dashboard.movies'));
        }

        $validated = $this->movie->getMovieLocally($id);

        if ($request->isMethod(Request::METHOD_POST)) {
            $video = $request->request->all();

            if (str_ends_with($video['title'], ')')) {
                $video['title'] = substr($video['title'],0, strrpos($video['title'],"-")+1);
                $video['title'] = trim($video['title'], '-');
            }

            if (!empty($video['videoFileId'])) {
                $validated['video_file_id'] = $video['videoFileId'];
                $file = File::load($video['videoFileId']);
                if ($file instanceof File) {
                    $validated['video_path'] = $file->getUri();
                    unset($video['videoFileId']);
                    unset($video['thumbnail']);
                    unset($video['videoFile']);
                }
                $validated['featured'] = !empty($video['featured']);
                unset($video['featured']);

                if (!empty($video['releaseYear'])) {
                    $validated['release_date'] = $video['releaseYear'];
                    unset($video['releaseYear']);
                }

                $validated = [
                    ...$validated,
                    ...$video
                ];

                $validated['featured'] = !empty($validated['featured']) ? 1 : 0;
                $validated['popularity'] = !empty($video['popularity']) ? $video['popularity'] : 0;
                if (!empty($validated['updated_at'])) {
                    unset($validated['updated_at']);
                }
                if(!empty($validated['created_at'])){
                    unset($validated['created_at']);
                }

                if ($this->movie->editMovie($id, $validated)) {
                    Message::info("Movie edited with title @title", ["@title" => $validated['title']]);
                    return $this->redirect(Url::routeByName('streamer.dashboard.movies'));
                }

            }
        }

        if (!empty($validated['video_file_id'])) {
            $file = File::load($validated['video_file_id']);
            if ($file instanceof File) {
                $validated['public_uri'] = "/sites/default". $file->getPublicUrl();
            }
        }

        if (!empty($validated['thumbnail_path'])) {

            if (str_starts_with($validated['thumbnail_path'], 'public://')) {
                $validated['thumbnail_uri'] = str_replace('public://', '/sites/default/', $validated['thumbnail_path']);
            }
            else {
                $validated['thumbnail_uri'] = "http://image.tmdb.org/t/p/w185". $validated['thumbnail_path'];
            }

        }

        return $this->renderTwig("@streamer/movie-edit.html.twig", $validated);
    }

    public function deleteMovie(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('movie_id');
        if (!empty($id)) {
            if ($this->movie->deleteMovie($id)) {
                Message::info("Deleted movie #@id", ["@id" => $id]);
                return $this->redirect(Url::routeByName('streamer.dashboard.movies'));
            }
        }
        return $this->redirect(Url::routeByName('streamer.dashboard.movies'));
    }

    public function moviesListing(Request $request, string $route_name, array $options): Response
    {
        $filters = [
            'title' => $request->query->get('title'),
            'genre' => $request->query->get('genre'),
            'year_from' => $request->query->get('year_from'),
            'year_to' => $request->query->get('year_to'),
            'rating_min' => $request->query->get('rating_min'),
            'featured' => $request->query->get('featured'),
            'page' => $request->query->get('page', 1),
            'limit' => $request->query->get('limit', 10)
        ];

        // Remove empty values
        $filters = array_filter($filters, function($value) {
            return $value !== null && $value !== '';
        });
        
        $result = $this->movie->getMoviesLocally($filters);

        $filterLists = $this->movie->getGenres([]);

        return $this->renderTwig("@streamer/movies.html.twig", [
            'movies' => $result['movies'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
            'list_filters' => $filterLists,
        ]);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function internalMediaDetail(Request $request, string $route_name, array $options): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!empty($data['searchType']) && $data['searchType'] == 'movie' && !empty($data['movieID'])) {
            $movie = $this->movie->getMovie(['movie_id' => $data['movieID']]);
            return new JsonResponse(['status'=>!empty($movie), 'movie' => $movie]);
        }
        elseif (!empty($data['searchType']) && $data['searchType'] == 'show' && !empty($data['showId'])) {
            $show = $this->show->getShow(['series_id' => $data['showId']]);
            return new JsonResponse(['status'=>!empty($movie), 'show' => $show]);
        }

        return new JsonResponse([]);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function internalUpload(Request $request, string $route_name, array $options): JsonResponse
    {
        /**@var FileSystem $fileSystem **/
        $fileSystem = \getAppContainer()->get('upload.service');

        /**@var UploadedFile $file **/
        $file = $request->files->get('file');
        $uploadType = $request->request->get('uploadType');

        if ($uploadType == 'video') {
            if ($file instanceof UploadedFile) {
                $extensions = ['video/mp4', 'video/mpeg', 'video/mov', 'video/x-matroska'];
                if ($file->getMimeType() && in_array($file->getMimeType(), $extensions)) {

                    $allowedSize = 2147483648;
                    if ($file->getSize() <= $allowedSize) {
                        $videoFile = "public://videos/".date("d-m-Y H-i-s");
                        @mkdir($videoFile, 0777, true);
                        $file = $file->move($videoFile, $file->getClientOriginalName());

                        /**@var File $fileEntity **/
                        $fileEntity = \getAppContainer()->get(File::class);

                        $fileEntity->setTitle($file->getFilename());
                        $fileEntity->setFileSize($file->getSize());
                        $fileEntity->setAlt($file->getFilename());
                        $fileEntity->setFieldName('videoFile');
                        $fileEntity->setFilename($file->getFilename());
                        $fileEntity->setFilemime($file->getMimeType());
                        $fileEntity->setUri($file->getPathname());
                        $fileEntity->setUid(\getAppContainer()->get('current_user')->getUserId());
                        $fileEntity->save();
                        $array = $fileEntity->toArray();

                        return new JsonResponse(['status'=> !empty($array['id']), 'file' => $array]);
                    }
                }
            }
        }

        elseif ($uploadType == 'image') {
            if ($file instanceof UploadedFile) {
                $extensions = ['image/jpg', 'image/jpeg', 'image/png', 'image/webp'];
                if ($file->getMimeType() && in_array($file->getMimeType(), $extensions)) {
                    $allowedSize = 2000000;
                    if ($file->getSize() <= $allowedSize) {
                        $videoFile = "public://images/".date("d-m-Y H-i-s");
                        @mkdir($videoFile, 0777, true);
                        $file = $file->move($videoFile, $file->getClientOriginalName());

                        /**@var File $fileEntity **/
                        $fileEntity = \getAppContainer()->get(File::class);

                        $fileEntity->setTitle($file->getFilename());
                        $fileEntity->setFileSize($file->getSize());
                        $fileEntity->setAlt($file->getFilename());
                        $fileEntity->setFieldName('thumbnail');
                        $fileEntity->setFilename($file->getFilename());
                        $fileEntity->setFilemime($file->getMimeType());
                        $fileEntity->setUri($file->getPathname());
                        $fileEntity->setUid(\getAppContainer()->get('current_user')->getUserId());
                        $fileEntity->save();
                        $array = $fileEntity->toArray();

                        return new JsonResponse(['status'=> !empty($array['id']), 'file' => $array]);
                    }
                }
            }
        }

        return new JsonResponse([]);
    }

    public function showsListing(Request $request, string $route_name, array $options): Response
    {
        $filters = [
            'title' => $request->query->get('title'),
            'genre' => $request->query->get('genre'),
            'year_from' => $request->query->get('year_from'),
            'year_to' => $request->query->get('year_to'),
            'rating_min' => $request->query->get('rating_min'),
            'status' => $request->query->get('status'),
            'language' => $request->query->get('language'),
            'page' => $request->query->get('page', 1),
            'limit' => $request->query->get('limit', 10)
        ];
        
        // Remove empty values
        $filters = array_filter($filters, function($value) {
            return $value !== null && $value !== '';
        });
        
        $result = $this->show->getShowsLocally($filters);
        
        return $this->renderTwig("@streamer/tv-shows.html.twig", [
            'shows' => $result['shows'],
            'pagination' => $result['pagination'],
            'filters' => $filters
        ]);
    }

    public function createShow(Request $request, string $route_name, array $options): Response
    {
        $validated = [];
        if ($request->isMethod(Request::METHOD_POST)) {
            $show = $request->request->all();
            $show['title'] = substr($show['title'],0, strrpos($show['title'],"-")+1);
            $show['title'] = trim($show['title'], '-');

            if (str_starts_with($show['season_thumbnail_path'] ?? "", 'public://')) {
                $show['season_thumbnail_uri'] = str_replace("public://", "/sites/default", $show['season_thumbnail_path']);
            }
            elseif (!str_starts_with($show['season_thumbnail_path'] ?? "", 'public://')) {
                $show['season_thumbnail_uri'] = "https://image.tmdb.org/t/p/w185/" . $show['season_thumbnail_path'];
            }

            if (str_starts_with($show['thumbnail_path'] ?? "", 'public://')) {
                $show['thumbnail_uri'] = str_replace("public://", "/sites/default", $show['thumbnail_path']);
            }
            elseif (!str_starts_with($show['thumbnail_path'] ?? "", 'public://')) {
                $show['thumbnail_uri'] = "https://image.tmdb.org/t/p/w185" . $show['thumbnail_path'];
            }

            $showData = [
                'title'  => $show['title'],
                'genres' => $show['genres'],
                'start_year' => $show['startYear'],
                'end_year'   =>  !empty($show['endYear']) ? $show['endYear'] : null,
                'rating'     =>  $show['rating'],
                'status'     =>  $show['status'],
                'description'=>  $show['description'],
                'language'   =>  $show['language'],
                'country'    =>  $show['country'],
                'poster'     =>  $show['thumbnail_path'],
                'thumbnail_path'=>  $show['thumbnail_path'],
                'season_count'  =>  $show['season_count'],
                'imdb_id'       =>  $show['imdb_id'],
            ];

            $id = $this->show->createShow($showData);

            if (!empty($show['createFirstSeason'])) {
                $firstSeason = [
                    'series_id' => $id,
                    'title'     =>  $show['firstSeasonTitle'],
                    'season_number' =>  $show['firstSeasonNumber'],
                    'vote_average'  =>  $show['firstSeasonVoteAverage'],
                    'episode_count' =>  $show['firstSeasonEpisodes'],
                    'air_date'      =>  !empty($show['firstSeasonYear']) ? $show['firstSeasonYear'] : null,
                    'description'   =>  $show['firstSeasonDescription'],
                    'poster'        =>  $show['season_thumbnail_path'],
                    'thumbnail_path' =>  $show['season_thumbnail_path'],
                    'imdb_id'        =>  $show['season_imdb_id'],
                ];
                $sid = $this->show->createSeason($firstSeason);

                if ($sid && $id) {
                    Message::info("Show Created with title @title", ['@title' => $show['title']]);
                    Message::info("First season for @showTitle created with title @title", ['@showTitle' => $show['title'], '@title' => $firstSeason['title']]);
                    return $this->redirect(Url::routeByName('streamer.dashboard.shows'));
                }

            }

            if (empty($show['createFirstSeason']) && $id) {
                Message::info("Show Created with title @title", ['@title' => $show['title']]);
                return $this->redirect(Url::routeByName('streamer.dashboard.shows'));
            }

            return $this->renderTwig("@streamer/tvshow-create.html.twig", $show);
        }
        return $this->renderTwig("@streamer/tvshow-create.html.twig", []);
    }

    public function editShow(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('show_id');
        $show = $this->show->getShowLocally($id);
        if ($show) {
            if (str_starts_with($show['thumbnail_path'] ?? "", 'public://')) {
                $show['thumbnail_uri'] = str_replace("public://", "/sites/default", $show['thumbnail_path']);
            }
            else {
                $show['thumbnail_uri'] = "https://image.tmdb.org/t/p/w185".$show['thumbnail_path'];
            }
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $data = $request->request->all();
            $data['title'] = substr($data['title'],0, strrpos($data['title'],"-")+1);
            $data['title'] = trim($data['title'], '-');
            $showData = [
                'title'  => $data['title'],
                'genres' => $data['genres'],
                'start_year' => $data['startYear'],
                'end_year'   =>  !empty($data['endYear']) ? $data['endYear'] : null,
                'rating'     =>  $data['rating'],
                'status'     =>  $data['status'],
                'description'=>  $data['description'],
                'language'   =>  $data['language'],
                'country'    =>  $data['country'],
                'poster'     =>  $data['thumbnail_path'],
                'thumbnail_path'=>  $data['thumbnail_path'],
            ];

            $show = $showData;
            if (str_starts_with($show['thumbnail_path'] ?? "", 'public://')) {
                $show['thumbnail_uri'] = str_replace("public://", "/sites/default", $show['thumbnail_path']);
            }
            else {
                $show['thumbnail_uri'] = "https://image.tmdb.org/t/p/w185".$show['thumbnail_path'];
            }

            if ($this->show->editShow($showData, $id)) {
                Message::info("Show Updated with title @title", ['@title' => $show['title']]);
                return $this->redirect(Url::routeByName('streamer.dashboard.shows'));
            }
        }
        return $this->renderTwig("@streamer/tvshow-edit.html.twig", $show);
    }

    /**
     * @throws DatabaseException
     */
    public function deleteShow(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('show_id');
        if ($this->show->deleteShow($id)) {
            Message::info("Deleted tv show");
            return $this->redirect(Url::routeByName('streamer.dashboard.shows'));
        }
        return $this->redirect(Url::routeByName('streamer.dashboard.shows'));
    }

    public function seasonsListing(Request $request, string $route_name, array $options): Response
    {
        $show_id = $request->query->get('show_id');
        $filters = [
            'show_id' => $show_id,
            'season_number' => $request->query->get('season_number'),
            'page' => $request->query->get('page', 1),
            'limit' => $request->query->get('limit', 10)
        ];
        
        // Remove empty values
        $filters = array_filter($filters, function($value) {
            return $value !== null && $value !== '';
        });
        
        $result = $this->show->getSeasonsLocally($filters);

        return $this->renderTwig("@streamer/tvshow-seasons.html.twig", [
            'show' => $this->show->getShowLocally($show_id),
            'seasons' => $result['seasons'],
            'pagination' => $result['pagination'],
            'filters' => $filters
        ]);
    }

    public function createSeason(Request $request, string $route_name, array $options): Response
    {
        $show_id = $request->query->get('show_id');
        $show = $this->show->getShowLocally($show_id);

        if ($request->isMethod(Request::METHOD_GET)) {
            Message::info("Creating season for @title",['@title' => $show['title']]);
        }

        $seasons = [];
        if (empty($_SESSION['seasons'][$show['imdb_id']])) {
            $seasons = $this->show->getShow(['series_id'=> $show['imdb_id']])['seasons'] ?? [];
            $_SESSION['seasons'][$show['imdb_id']] = $seasons;
        }
        else {
            $seasons = $_SESSION['seasons'][$show['imdb_id']];
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $data = $request->request->all();
            $seasonFilter = array_filter($seasons, function ($season) use ($data) {
                return in_array($season['id'], $data['season_id'] ?? []);
            });

            if (!empty($data['all'])) {
                $seasonFilter = $seasons;
            }

            foreach ($seasonFilter as $season) {
                $season['series_id'] = $show_id;
                $season['description'] = $season['overview'];
                $season['poster'] = $season['poster_path'];
                $season['imdb_id'] = $season['id'];
                $season['thumbnail_path'] = $season['poster_path'];
                $season['title'] = $season['name'];
                unset($season['overview']);
                unset($season['poster_path']);
                unset($season['name']);
                unset($season['id']);
                if ($this->show->createSeason($season)) {
                    Message::info("Created season for @title", ['@title' => $season['title']]);
                }
            }
            return $this->redirect(Url::routeByName('streamer.dashboard.seasons', ['show_id'=>$show_id]));
        }
        return $this->renderTwig("@streamer/season-create.html.twig", [
            'show' => $show,
            'seasons' => $seasons,
        ]);
    }

    public function deleteSeason(Request $request, string $route_name, array $options): Response
    {
        $season_id = $request->query->get('season_id');
        $season = $this->show->getSeasonLocally($season_id);

        if ($season && $this->show->deleteSeasonLocally($season_id)) {
            Message::info("Deleted season for @title", ['@title' => $season['title']]);

            return $this->redirect(Url::routeByName('streamer.dashboard.seasons',
                ['show_id'=>$request->query->get('show_id')]));
        }

        return $this->redirect(Url::routeByName('streamer.dashboard.seasons',
            ['show_id'=>$request->query->get('show_id')]));
    }

    public function episodesListing(Request $request, string $route_name, array $options): Response
    {
        $show_id = $request->query->get('show_id');
        $season_id = $request->query->get('season_id');
        $show = $this->show->getShowLocally($show_id);
        $season = $this->show->getSeasonLocally($season_id);
        
        $filters = [
            'season_id' => $season_id,
            'episode_number' => $request->query->get('episode_number'),
            'page' => $request->query->get('page', 1),
            'limit' => $request->query->get('limit', 10)
        ];
        
        // Remove empty values
        $filters = array_filter($filters, function($value) {
            return $value !== null && $value !== '';
        });
        
        $result = $this->show->getEpisodesLocally($filters);

        return $this->renderTwig("@streamer/season-episodes.html.twig", [
            'show' => $show,
            'season' => $season,
            'episodes' => $result['episodes'],
            'pagination' => $result['pagination'],
            'filters' => $filters
        ]);
    }

    public function createEpisode(Request $request, string $route_name, array $options): Response
    {
        $show_id = $request->query->get('show_id');
        $season_id = $request->query->get('season_id');
        $show = $this->show->getShowLocally($show_id);
        $season = $this->show->getSeasonLocally($season_id);
        $validated = [];
        $episodes = [];
        if (empty($_SESSION['episodes'][$show_id][$season_id])) {
            $externalSeason = $this->show->getSeason(['series_id'=>$show['imdb_id'], 'season_number'=> $season['season_number']]);
            $episodes = $externalSeason['episodes'] ?? [];
            $_SESSION['episodes'][$show_id][$season_id] = $episodes;
        }
        else {
            $episodes = $_SESSION['episodes'][$show_id][$season_id];
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $data = $request->request->all();
            $validated = $data;
            $file = File::load($data['videoFileId']);
            if ($file instanceof File) {
                $validated['public_uri'] = "/sites/default" . $file->getPublicUrl();
            }

            $selectedEpisode = array_filter($episodes, function ($episode) use ($data) {
                return $episode['id'] == $data['episodes'];
            });

            if ($selectedEpisode) {
                $selectedEpisode = reset($selectedEpisode);
                $selectedEpisode['guest_stars'] = json_encode($selectedEpisode['guest_stars']);
                $selectedEpisode['crew'] = json_encode($selectedEpisode['crew']);
                $selectedEpisode['imdb_id'] = $selectedEpisode['id'];
                unset($selectedEpisode['id']);
                unset($selectedEpisode['show_id']);
                $selectedEpisode['series_id'] = $show_id;
                $selectedEpisode['season_id'] = $season_id;
                $selectedEpisode['video_id']  = !empty($data['videoFileId']) ? $data['videoFileId'] : null;

                if ($this->show->createEpisode($selectedEpisode)) {
                    Message::info("Created episode for @title", ['@title' => $selectedEpisode['name']]);
                    return $this->redirect(Url::routeByName('streamer.dashboard.episodes',[
                        'show_id'=>$show_id,
                        'season_id'=>$season_id,
                    ]));
                }
            }


        }
        return $this->renderTwig("@streamer/episode-create.html.twig", [
            'episodes' => $episodes,
            'show' => $show,
            'season' => $season,
            ...$validated
        ]);
    }

    public function editEpisode(Request $request, string $route_name, array $options): Response
    {
        $show_id = $request->query->get('show_id');
        $season_id = $request->query->get('season_id');
        $show = $this->show->getShowLocally($show_id);
        $season = $this->show->getSeasonLocally($season_id);
        $episode_id = $request->query->get('episode_id');
        $episode = $this->show->getEpisodeLocally($episode_id);
        $validated = [];

        if ($request->isMethod(Request::METHOD_POST)) {
            $data = $request->request->all();
            $validated = $data;
            $file = File::load($data['videoFileId']);
            if ($file instanceof File) {
                $validated['public_uri'] = "/sites/default" . $file->getPublicUrl();
            }

            if ($validated) {
                $dd['video_id']  = !empty($data['videoFileId']) ? $data['videoFileId'] : null;

                if ($this->show->editEpisode($dd, $episode_id)) {
                    Message::info("Edited episode #".$episode_id);
                    return $this->redirect(Url::routeByName('streamer.dashboard.episodes',[
                        'show_id'=>$show_id,
                        'season_id'=>$season_id,
                    ]));
                }
            }


        }

        if (!empty($episode['video_id'])) {
            $file = File::load($episode['video_id']);
            if ($file instanceof File) {
                $validated['public_uri'] = "/sites/default" . $file->getPublicUrl();
            }
        }

        if (!empty($episode['still_path'])) {
            if (str_starts_with($episode['still_path'], 'public://')) {
                $validated['thumbnail_uri'] = str_replace('public://', '/sites/default', $episode['still_path']);
            }
            else {
                $validated['thumbnail_uri'] = "https://image.tmdb.org/t/p/w185".$episode['still_path'];
            }
        }

        return $this->renderTwig("@streamer/episode-edit.html.twig", [
            'show' => $show,
            'season' => $season,
            'episode_id' => $episode_id,
            ...$validated
        ]);
    }

    public function deleteEpisode(Request $request, string $route_name, array $options): Response
    {
        $episode_id = $request->query->get('episode_id');
        if ($this->show->deleteEpisode($episode_id)) {
           Message::info("Deleted episode #".$episode_id);
        }
        return $this->redirect(Url::routeByName('streamer.dashboard.episodes',[
            'show_id'=>$request->query->get('show_id'),
            'season_id'=>$request->query->get('season_id'),
        ]));
    }

    public function playlistListing(Request $request, string $route_name, array $options): Response
    {
        $filters = [
            'title' => $request->query->get('title'),
            'is_public' => $request->query->get('is_public'),
            'page' => $request->query->get('page', 1),
            'limit' => $request->query->get('limit', 10)
        ];
        
        // Remove empty values
        $filters = array_filter($filters, function($value) {
            return $value !== null && $value !== '';
        });
        
        $result = $this->playlist->getPlaylists($filters);
        
        return $this->renderTwig("@streamer/playlists.html.twig", [
            'playlists' => $result['playlists'],
            'pagination' => $result['pagination'],
            'filters' => $filters
        ]);
    }

    /**
     * @throws DatabaseException
     * @throws RandomException
     * @throws NotFoundException
     * @throws DependencyException
     */
    public function createPlaylist(Request $request, string $route_name, array $options): Response
    {

        $formData = [
            'genres' => $this->movie->getGenres([]),
            'cuts'   => []
        ];

        if ($request->isMethod(Request::METHOD_POST)) {

            $data = $request->request->all();

            if (!empty($data['playlistType']) && $data['playlistType'] === 'manual') {
                if (!empty($data['playlistName'])) {
                    if ($id = $this->playlist->createPlaylist($data['playlistName'], $data['playlistDescription'], !empty($data['playlistPublic'])))
                    {
                        $playlist = $this->playlist->getPlaylist(pid: $id);
                        if (!empty($playlist['session_token'])) {
                            foreach ($data['movieSelect'] as $movie_id) {
                                $this->playlist->addPlaylistItem($playlist['session_token'], $movie_id);
                            }
                        }
                        return $this->redirect(Url::routeByName('streamer.dashboard.playlists'));
                    }
                }
            }
            elseif (!empty($data['playlistType']) && $data['playlistType'] === 'auto'){
                $movies = $this->playlist->getMovie($data);
                if (!empty($data['autoPlaylistName'])) {
                    if ($id = $this->playlist->createPlaylist($data['autoPlaylistName'], $data['playlistDescription'], !empty($data['playlistPublic'])))
                    {
                        $playlist = $this->playlist->getPlaylist(pid: $id);
                        if (!empty($playlist['session_token'])) {
                            foreach ($movies as $movie) {
                                $this->playlist->addPlaylistItem($playlist['session_token'], $movie['id']);
                            }
                        }
                        return $this->redirect(Url::routeByName('streamer.dashboard.playlists'));
                    }

                }
            }

            return $this->redirect(Url::routeByName('streamer.dashboard.playlists.add'));
        }

        return $this->renderTwig("@streamer/playlist-create.html.twig",$formData);
    }

    /**
     * @throws DatabaseException
     */
    public function deletePlaylist(Request $request, string $route_name, array $options): Response
    {
        $playlist_id = $request->query->get('playlist_id');
        if ($this->playlist->deletePlaylist($playlist_id)) {
            Message::info("Deleted playlist #".$playlist_id);
        }
        return $this->redirect(Url::routeByName('streamer.dashboard.playlists'));
    }

    public function internalLocalDetail(Request $request, string $route_name, array $options): JsonResponse|array
    {
        $content = json_decode($request->getContent(), true);
        if (!empty($content['movieId'])) {
            $movie = $this->movie->getMovieLocally($content['movieId']);
            if (!empty($movie['video_file_id'])) {
                $file = File::load($movie['video_file_id']);
                if ($file instanceof File) {
                    $path = $file->getUri();
                    $data = [
                        'uri' => str_replace('public://', '/sites/default/files/', $path),
                        'id'  => $movie['id'],
                        'type' => $file->getFilemime(),
                        'title' => $movie['title'],
                    ];
                    return new JsonResponse($data);
                }
            }
        }
        return [];
    }

}