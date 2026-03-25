<?php

namespace Simp\Pindrop\Modules\streamer\src\Plugin;

use Simp\Pindrop\Entity\File\File;
use Simp\VideoPhp\video\Video;

class PlayerManifestManager
{
    public function __construct(protected Show $show, protected Movie $movie)
    {
    }

    public function createManifestFromShow(int $id, ?int $sid = null): array
    {
        $seasons = [];

        if (empty($sid)) {
            $seasons = $this->show->getSeasonsLocally([
                'series_id' => $id,
                'limit' => 100
            ])['seasons'] ?? [];
        }
        else {
            $seasons[] = $this->show->getSeasonLocally($sid);
        }

        foreach ($seasons as $k=>$season) {
            if (!empty($season['id'])) {

                $episodes = $this->show->getEpisodesLocally([
                    'season_id' => $season['id'],
                    'limit' => 100
                ]);

                if (!empty($episodes['episodes'])) {
                    $seasons[$k]['episodes'] =  $episodes['episodes'];
                }
                else {
                    unset($seasons[$k]);
                }

            }

        }

        return [
            'version' => 1,
            'created_at' => date('c'),
            'segments'  =>  $this->generateSegments($seasons),
            'frames'    =>  $this->generateFrames($seasons),
        ];
    }

    private function generateSegments(mixed $seasons): array
    {
        $segments = [];

        $videoProcessor = Video::metadata();
        foreach ($seasons ?? [] as $season) {

            foreach ($season['episodes'] as $episode) {
                if (!empty($episode['video_id'])) {
                    $file = File::load($episode['video_id']);
                    $filepath = $file->getUri();
                    $filepath = str_replace("public://", "sites/default/files/", $filepath);
                    $metadata = $videoProcessor->getSummary($filepath);
                    $metadata['filename'] = $episode['episode_number'] ." - ". ( $episode['name'] ?? "Episode ". $episode['episode_number']);
                    $segments[] = [
                        'path' => $episode['id'],
                        'metadata' => $metadata,
                        'segment_frame' => $episode['id'],
                        'description'   => $episode['overview'],
                    ];
                }
            }

        }

        return $segments;
    }

    private function generateFrames(mixed $seasons): array
    {
        $frames = [];
        foreach ($seasons as $season) {
            foreach ($season['episodes'] as $episode) {
                if (isset($episode['still_path'])) {
                    $frames[] = $episode['id'];
                }
            }
        }
        return $frames;
    }

    public function createManifestFromMovie(int $movie_id): array
    {
        $movie = $this->movie->getMovieLocally($movie_id);
        if (empty($_SESSION['movie_player_season'][$movie_id]['images'])) {
            $images = $this->movie->getImages(['movie_id' => $movie['imdb_id']]);
            if ($images) {
                $images = array_slice($images['backdrops'], 0, 5);
                $_SESSION['movie_player_season'][$movie_id]['images'] = $images;
            }
        }

        $images = $_SESSION['movie_player_season'][$movie_id]['images'] ?? [];

        return [
            'version' => 1,
            'created_at' => date('c'),
            'segments'  =>  $this->generateMovieSegments($movie),
            'frames'    =>  $this->generateMovieFrames($images),
        ];

    }

    private function generateMovieSegments(array $movie): array
    {
        $segments = [];
        $videoProcessor = Video::metadata();
        if (!empty($movie['video_file_id'])) {
            $file = File::load($movie['video_file_id']);
            if ($file instanceof File) {
                $filepath = $file->getUri();
                $filepath = str_replace("public://", "sites/default/files/", $filepath);
                $metadata = $videoProcessor->getSummary($filepath);
                $metadata['filename'] = $movie['title'];
                $segments[] = [
                    'path' => $movie['id'],
                    'metadata' => $metadata,
                    'segment_frame' => $movie['id'],
                    'description'   => $movie['description'],
                ];
            }
        }

        return $segments;
    }

    private function generateMovieFrames(mixed $images): array
    {
        $frames = [];
        foreach ($images as $key=>$image) {
            $frames[] = $key;
        }
        return $frames;
    }

    public function getMovieImage(int $movie_id, int $index): array
    {
        if (empty($_SESSION['movie_player_season'][$movie_id]['images'])) {
            $images = $this->movie->getImages(['movie_id' => $movie_id]);

            if ($images) {
                $images = array_slice($images['backdrops'], 0, 5);
                $_SESSION['movie_player_season'][$movie_id]['images'] = $images;
            }
        }

        $images = $_SESSION['movie_player_season'][$movie_id]['images'] ?? [];
        return $images[$index] ?? [];
    }

    public function createManifestFromPlaylist(array $playlistItems)
    {
        $segments = [];
        foreach ($playlistItems as $playlistItem) {
            $movie = $this->movie->getMovieLocally($playlistItem['video_id']);
            if (!empty($movie)) {
                $segment = $this->generateMovieSegments($movie);
                $segments[] = reset($segment);
            }
        }
        return [
            'version' => 1,
            'created_at' => date('c'),
            'segments'  =>  $segments,
            'frames'    =>  [],
        ];
    }
}