<?php

namespace Simp\Pindrop\Modules\music_cron\src\Plugin\Subscriber;

use Simp\Pindrop\Modules\cron\src\Plugin\Subscriber\ScheduleSubscriber;
use Simp\Pindrop\Modules\music_cron\src\Service\SpotifyAlbum;

class SpotifyAlbumDownloader extends ScheduleSubscriber
{
    public function name(): string
    {
        return 'Spotify Album Downloader';
    }

    public function id(): string
    {
        return 'music.spotify.downloader';
    }

    public function runSchedules(array $schedules): string
    {
        if (empty($schedules)) {
            return '';
        }

        /**
         * @var SpotifyAlbum
         */
        $downloader = getAppContainer()->get('music_cron.downloader');

        $entries = [];
        $logger = function (string $message, string $type) use (&$entries) {
            $entries[] = [$message, $type];
        };

        $downloader->downloadAlbum($logger);

        foreach ($schedules as $schedule) {
            foreach ($entries as [$message, $type]) {
                $schedule->addLog($message, $type);
            }
            $schedule->finished();
        }

        return "Done";
    }
}
