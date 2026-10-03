<?php

namespace Simp\Pindrop\Modules\yt_dlp_downloader\src\Plugin\CronDefinition;

use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionInterface;

class YtCronDefinition implements CronDefinitionInterface
{
    public function key(): string
    {
        return 'yt_dlp_downloader_cron';
    }

    public function name(): string
    {
        return 'YT DLP Downloader Cron';
    }

    public function getSubscribers(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'This cron job processes pending YouTube download links using yt-dlp and updates their status in the database.';
    }
}
