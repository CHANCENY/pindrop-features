<?php

namespace Simp\Pindrop\Modules\streamer\src\Services;

class Logs
{
    // 🎬 Movie Logs
    public const string MOVIE_UPLOADED = "Movie uploaded";
    public const string MOVIE_DOWNLOADED = "Movie downloaded";
    public const string MOVIE_NOT_UPLOADED = "Movie not uploaded";
    public const string MOVIE_NOT_DOWNLOADED = "Movie not downloaded";
    public const string MOVIE_EDITED = "Movie edited";
    public const string MOVIE_NOT_EDITED = "Movie not edited";
    public const string MOVIE_DELETED = "Movie deleted";
    public const string MOVIE_NOT_DELETED = "Movie not deleted";

    // 📺 Show Logs
    public const string SHOW_CREATED = "Show created";
    public const string SHOW_NOT_CREATED = "Show not created";
    public const string SHOW_EDITED = "Show edited";
    public const string SHOW_NOT_EDITED = "Show not edited";
    public const string SHOW_DELETED = "Show deleted";
    public const string SHOW_NOT_DELETED = "Show not deleted";

    // 📂 Season Logs
    public const string SEASON_CREATED = "Season created";
    public const string SEASON_NOT_CREATED = "Season not created";
    public const string SEASON_EDITED = "Season edited";
    public const string SEASON_NOT_EDITED = "Season not edited";
    public const string SEASON_DELETED = "Season deleted";
    public const string SEASON_NOT_DELETED = "Season not deleted";

    // 🎞 Episode Logs
    public const string EPISODE_UPLOADED = "Episode uploaded";
    public const string EPISODE_NOT_UPLOADED = "Episode not uploaded";
    public const string EPISODE_EDITED = "Episode edited";
    public const string EPISODE_NOT_EDITED = "Episode not edited";
    public const string EPISODE_DELETED = "Episode deleted";
    public const string EPISODE_NOT_DELETED = "Episode not deleted";

    // 📃 Playlist Logs
    public const string PLAYLIST_CREATED = "Playlist created";
    public const string PLAYLIST_NOT_CREATED = "Playlist not created";
    public const string PLAYLIST_EDITED = "Playlist edited";
    public const string PLAYLIST_NOT_EDITED = "Playlist not edited";
    public const string PLAYLIST_DELETED = "Playlist deleted";
    public const string PLAYLIST_NOT_DELETED = "Playlist not deleted";

    public static function addLog(string $title, string $type): int
    {
        // Get all class constants
        $reflection = new \ReflectionClass(self::class);
        $allowedTypes = array_values($reflection->getConstants());

        // Validate type
        if (!in_array($type, $allowedTypes, true)) {
            throw new \InvalidArgumentException("Invalid log type: {$type}");
        }

        $database = \getAppContainer()->get('database');
        $query = $database->query("INSERT INTO recent_log (title, type) VALUES (:title, :type)", ...$i=[':title' => $title, 'type' => $type]);
        return $database->lastInsertId();
    }

    public static function getLogs(string $type) {
        $database = \getAppContainer()->get('database');
        $query = $database->query("SELECT * FROM recent_log WHERE type = :type ORDER BY created_at DESC", ...$i=[':type' => $type]);
        return $query->fetchAll();
    }

    public static function getRecentLogs(int $limit = 5)
    {
        $database = \getAppContainer()->get('database');
        $query = $database->query("SELECT * FROM recent_log ORDER BY created_at DESC LIMIT $limit");
        return $query->fetchAll();
    }
}