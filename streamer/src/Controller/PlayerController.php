<?php

namespace Simp\Pindrop\Modules\streamer\src\Controller;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Entity\File\File;
use Simp\Pindrop\Modules\streamer\src\Plugin\Movie;
use Simp\Pindrop\Modules\streamer\src\Plugin\PlayerManifestManager;
use Simp\Pindrop\Modules\streamer\src\Plugin\Playlist;
use Simp\Pindrop\Modules\streamer\src\Plugin\Show;
use Simp\Pindrop\Modules\streamer\src\Services\PlayerActivityRecorder;
use Simp\Pindrop\Routing\Url;
use Simp\VideoPhp\player\VideoPlayerStreamer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PlayerController extends ControllerBase
{
    public function __construct(protected Show $show, protected Movie $movie,
                                protected PlayerManifestManager $playerManifestManager,
                                protected Playlist $playlist)
    {
        parent::__construct();
    }

    public static function create(Container $container): PlayerController
    {
        return new self(
            $container->get('streamer.show'),
            $container->get('streamer.movie'),
            $container->get('streamer.player_manifest_manager'),
            $container->get('streamer.playlist')
        );
    }

    public function watchShow(Request $request, string $route_name, array $options)
    {
        $show_id = $request->query->get('show_id');
        $season_id = $request->query->get('sid');

        $seasons = $this->show->getSeasonsLocally(['series_id' => $show_id, 'limit' => 500])['seasons'] ?? [];

        if (empty($seasons)) {
            return $this->redirect(Url::routeByName("streamer_frontend.front.show.detail",['show_id' => $show_id]));
        }


        if (empty($season_id) && !empty($seasons)) {
            $season_id = $seasons[0]['id'];
            return $this->redirect(Url::routeByName("streamer.dashboard.shows.watch",['show_id' => $show_id, 'sid'=>$season_id]));
        }

        if ($show_id) {
            $show = $this->show->getShowLocally($show_id);
            $season = $this->show->getSeasonLocally($season_id);

            return $this->renderTwig("@streamer/player/show.html.twig", [
                'show' => $show,
                'season' => $season,
                'unique' => time(),
                'seasonsList' => $seasons,
            ]);
        }

        return "<h1>Sorry missing parameters in request</h1>";
    }

    public function playListEpisodes(Request $request, string $route_name, array $options): JsonResponse
    {
        $show_id = $request->query->get('show_id');
        $season_id = $request->query->get('season_id');
        if ($show_id && $season_id) {
            $manifest = $this->playerManifestManager->createManifestFromShow($show_id,$season_id);
            return new JsonResponse($manifest);
        }

        return new JsonResponse([]);
    }

    public function playListEpisodeFrame(Request $request, string $route_name, array $options): Response
    {
        $show_id = $request->query->get('show_id');
        $episode_id = $request->query->get('i');
        if ($show_id && $episode_id) {
            $episode = $this->show->getEpisodeLocally($episode_id);
            if (!empty($episode['still_path']) && $episode['series_id'] == $show_id) {

                $file = "";
                if (str_starts_with($episode['still_path'], 'public://')) {
                    $mime_type = mime_content_type($episode['still_path']);
                    return new Response(file_get_contents($episode['still_path']),200,[
                        'Content-Type' => $mime_type,
                    ]);
                }
                $link = "https://image.tmdb.org/t/p/w500". $episode['still_path'];
                return new Response(file_get_contents($link),200,[
                    'Content-Type' => 'application/octet-stream',
                ]);

            }
            return new Response("no image");
        }

        return new Response("no image");
    }

    public function playListEpisodeVideo(Request $request, string $route_name, array $options): Response
    {
        $show_id = $request->query->get('show_id');
        $episode_id = $request->query->get('v');
        if ($show_id && $episode_id) {

            $episode = $this->show->getEpisodeLocally($episode_id);
            if (!empty($episode['video_id']) && $episode['series_id'] == $show_id) {
                $file = File::load($episode['video_id']);
                if ($file instanceof File) {
                    $path = $file->getUri();
                    $streamer = new VideoPlayerStreamer(8192);
                    $streamer->stream($path);
                    exit;
                }
            }

        }

        return new Response("no video");
    }

    public function watchMovie(Request $request, string $route_name, array $options): Response
    {
        $movie_id = $request->query->get('movie_id');
        if ($movie_id) {
            $movie = $this->movie->getMovieLocally($movie_id);
            return $this->renderTwig('@streamer/player/movie.html.twig', [
                'movie' => $movie,
                'unique' => time(),
            ]);
        }
        return new Response("No movie found with Id #{$movie_id}");
    }

    public function playListMovie(Request $request, string $route_name, array $options): JsonResponse
    {
        $movie_id = $request->query->get('movie_id');
        if ($movie_id) {
            $manifest = $this->playerManifestManager->createManifestFromMovie($movie_id);
            return new JsonResponse($manifest);
        }
        return new JsonResponse([]);
    }

    public function playListMovieFrame(Request $request, string $route_name, array $options)
    {
        $movie_id = $request->query->get('movie_id');
        $i = $request->query->get('i');
        if ($movie_id && $i) {
            $movie = $this->movie->getMovieLocally($movie_id);
            if (!empty($movie) && intval($i) !== $movie['id']) {

               $image = $this->playerManifestManager->getMovieImage($movie_id, $i);
               if (!empty($image)) {
                   $link = "https://image.tmdb.org/t/p/w185". $image['file_path'];
                   return new Response(file_get_contents($link),200,[
                       'Content-Type' => 'application/octet-stream',
                   ]);
               }

            }
            else {
                if (str_starts_with($movie['thumbnail_path'], 'public://')) {
                    $mime_type = mime_content_type($movie['thumbnail_path']);
                    return new Response(file_get_contents($movie['thumbnail_path']),200,[
                        'Content-Type' => $mime_type,
                    ]);
                }
                $link = "https://image.tmdb.org/t/p/w185". $movie['thumbnail_path'];
                return new Response(file_get_contents($link),200,[
                    'Content-Type' => 'application/octet-stream',
                ]);
            }
        }
        return new Response("no image");
    }

    public function playListMovieVideo(Request $request, string $route_name, array $options)
    {
        $movie_id = $request->query->get('movie_id');
        if ($movie_id) {
            $movie = $this->movie->getMovieLocally($movie_id);
            if (!empty($movie['video_file_id'])) {
                $file = File::load($movie['video_file_id']);
                if ($file instanceof File) {
                    $path = $file->getUri();
                    $streamer = new VideoPlayerStreamer(8192);
                    $streamer->stream($path);
                    exit;
                }
            }
        }
        return new Response("no video");
    }

    /**
     * @throws DatabaseException
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function playerRecorder(Request $request, string $route_name, array $options)
    {
        $content = json_decode($request->getContent(), true);
        $validated = [];
        $content['ip_address'] = $request->getClientIp();

        if (!empty($content['user_id'])) {
            $validated['user_id'] = $content['user_id'];
        }

        if (!empty($content['current_time_played'])) {
            $validated['current_time_played'] = $content['current_time_played'];
        }

        if (!empty($content['ip_address'])) {
            $validated['ip_address'] = $content['ip_address'];
        }

        if (!empty($content['user_agent'])) {
            $validated['user_agent'] = $content['user_agent'];
        }

        if (!empty($content['event'])) {
            $validated['event'] = $content['event'];
        }

        if (!empty($content['duration'])) {
            $validated['duration'] = $content['duration'];
        }

        if (!empty($content['media'])) {
            $validated['media'] = $content['media'];
        }

        if (!empty($content['video_id'])) {
            $validated['video_id'] = $content['video_id'];
        }
        else {
            return new JsonResponse([]);
        }

        if (!empty($content['event'])) {
            $validated['event'] = $content['event'];
        }
        else {
            return new JsonResponse([]);
        }

        $recorder = new PlayerActivityRecorder(\getAppContainer()->get('database'));
        $recorder->addRecord($validated);

        return new JsonResponse([
            'success' => true,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function watchPlaylist(Request $request, string $route_name, array $options): Response
    {
        $session_token = $request->query->get('session_token');
        if ($session_token) {
            $playlist = $this->playlist->getPlaylist(pid: $session_token);
            return $this->renderTwig('@streamer/player/playlist.html.twig', [
                'playlist' => $playlist,
                'unique' => time(),
            ]);
        }
        return new Response("no session token");
    }

    /**
     * @throws DatabaseException
     */
    public function playlistMetadata(Request $request, string $route_name, array $options): JsonResponse
    {
        $playlist_id = $request->query->get('playlist_id');
        if ($playlist_id) {
            $playlistItems = $this->playlist->getPlaylistItems(pid: $playlist_id);
            $manifest = $this->playerManifestManager->createManifestFromPlaylist($playlistItems);
            return new JsonResponse($manifest);
        }
        return new JsonResponse([]);
    }

    public function playListFrame(Request $request, string $route_name, array $options): Response
    {
        $i = $request->query->get('i');
        if ($i) {

            $movie = $this->movie->getMovieLocally($i);
            if (str_starts_with($movie['thumbnail_path'], 'public://')) {
                $mime_type = mime_content_type($movie['thumbnail_path']);
                return new Response(file_get_contents($movie['thumbnail_path']),200,[
                    'Content-Type' => $mime_type,
                ]);
            }
            $link = "https://image.tmdb.org/t/p/w185". $movie['thumbnail_path'];
            return new Response(file_get_contents($link),200,[
                'Content-Type' => 'application/octet-stream',
            ]);

        }

        return new Response("no image");
    }

    public function playListVideo(Request $request, string $route_name, array $options): Response
    {
        $movie_id = $request->query->get('v');
        if ($movie_id) {
            $movie = $this->movie->getMovieLocally($movie_id);
            if (!empty($movie['video_file_id'])) {
                $file = File::load($movie['video_file_id']);
                if ($file instanceof File) {
                    $path = $file->getUri();
                    $streamer = new VideoPlayerStreamer(8192);
                    $streamer->stream($path);
                    exit;
                }
            }
        }
        return new Response("no video");
    }

}