<?php

/**
 * This service is for cron spotify album downloader.
 */

namespace Simp\Pindrop\Modules\music_cron\src\Service;

use Simp\Pindrop\Database\DatabaseService;

class SpotifyAlbum
{
    const TABLE = 'music_cron_spotify_album';
    const DOWNLOAD_TABLE = 'music_cron_spotify_download';

    public function __construct(protected DatabaseService $database_service)
    {
        
    }

    public function addSpotifyAlbum(string $uri): bool|int
    {
        return $this->database_service->table(self::DOWNLOAD_TABLE)
        ->insert([
            'url'  =>  $uri,
            'status' => 'pending',
            'album_name' => 'Not Yet'
        ]);
    }

    public function downloadSpotifyProgressStatus(): array
    {
        return $this->database_service->table(self::DOWNLOAD_TABLE)
        ->latest('created_at')
        ->get();
    }

    public function downloadAlbum(callable $logger): string
    {
        return "downloaded 3MB of 113MB";
    }
}
