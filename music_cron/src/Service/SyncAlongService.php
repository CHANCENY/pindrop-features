<?php

namespace Simp\Pindrop\Modules\music_cron\src\Service;

use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;

class SyncAlongService
{

    public function __construct(protected MediaUrlService $media_url_service)
    {
        
    }
    public function convertPlainLyrics(string $lyric_path, string $track_path): void
    {
        $lyric_path = $_ENV['PUBLIC_STREAM_DIR'] . $this->media_url_service->url($lyric_path);
        $track_path = $_ENV['PUBLIC_STREAM_DIR'] . $this->media_url_service->url($track_path);

        

    }
}
