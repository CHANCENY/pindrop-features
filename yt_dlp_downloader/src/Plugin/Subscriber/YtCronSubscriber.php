<?php

namespace Simp\Pindrop\Modules\yt_dlp_downloader\src\Plugin\Subscriber;

use Simp\Pindrop\Modules\cron\src\Plugin\Cron\Schedule;
use Simp\Pindrop\Modules\cron\src\Plugin\Subscriber\ScheduleSubscriber;
use Simp\Pindrop\Modules\ffmpeg_worker\binaries\Binary;
use Simp\Pindrop\Modules\yt_dlp_downloader\src\services\Yt;

class YtCronSubscriber extends ScheduleSubscriber
{
    public function name(): string
    {
        return 'YT DLP Downloader Cron Subscriber';
    }

    public function id(): string
    {
        return 'yt_dlp_downloader_cron_subscriber';
    }

    public function runSchedules(array $schedules): string
    {
        /**
         * @var Yt $yt_service
         */
        $yt_service = \getAppContainer()->get(Yt::class);

        $pending_links = $yt_service->getPendingLinks();

        if (empty($pending_links)) {
            foreach ($schedules as $schedule) {
                $schedule->addLog('No pending links to process.', 'info');
                $schedule->finished();
            }
        }

        $entries = [];
        $logger = function (string $message, string $type) use (&$entries) {
            $entries[] = [$message, $type];
        };

        foreach ($schedules as $schedule) {
            $schedule->addLog('YT DLP Downloader Cron run started', 'start');
        }

        foreach ($pending_links as $link_data) {
            $local_path = $link_data['local_path'] ?? null;
            $link = $link_data['link'] ?? null;
            $name = $link_data['name'] ?? null;

            foreach ($schedules as $schedule) {
                
                if ($local_path && $link && $name) {
                    $logger("Processing link: $link named $name", 'info');
                    $schedule->addLog("Processing link: {$link}", 'info');
                    $yt_service->updateLinkStatus($link_data['id'], 'downloading');

                    if (!is_dir($local_path)) {
                        mkdir($local_path);
                    }

                    if (is_dir($local_path)) {
                        $special_path = rtrim($local_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
                        if (!is_dir($special_path)) {
                            mkdir($special_path);
                        }

                        // determine if the link is a playlist or a single video
                        $parsed_url = parse_url($link);
                        parse_str($parsed_url['query'] ?? '', $query_params);

                        if (isset($query_params['list'])) {
                            $this->downloadPlaylistAudios($link, $special_path, $logger, $schedule);
                        } else {
                            $this->downloadAudio($link, $special_path, $logger, $schedule);
                        }

                    }

                    $yt_service->updateLinkStatus($link_data['id'], 'completed');
                    $logger("Successfully processed link: $link", 'success');
                    $schedule->addLog("Successfully processed link: {$link}", 'success');
                    $schedule->finished();

                } else {
                    $logger("Invalid link data: " . json_encode($link), 'error');
                    $schedule->addLog("Invalid link data: " . json_encode($link), 'error');
                    $schedule->finished();
                }
            }
        }
        return '';
    }

    function downloadPlaylistAudios(string $url, string $destination, callable $logger, Schedule $schedule): void
    {

        if (!$url) {
            $logger("Error: No playlist URL provided.", 'error');
            $schedule->addLog("Error: No playlist URL provided.", 'error');
            return;
        }

        if (!$destination) {
            $logger("Error: No destination provided.", 'error');
            $schedule->addLog("Error: No destination provided.", 'error');
            return;
        }

        $destination = rtrim($destination, DIRECTORY_SEPARATOR);

        if (!is_dir($destination)) {
            if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
                $logger("Error: Could not create destination directory: {$destination}", 'error');
                $schedule->addLog("Error: Could not create destination directory: {$destination}", 'error');
                return;
            }
        }

        // Suppress PHP warnings.
        error_reporting(E_ERROR | E_PARSE);

        $binaryPath =
            \Simp\Pindrop\Modules\yt_dlp_downloader\src\binary\YTBinary::getBinaryPath();

        $ffmpeg = new Binary();

        $ffmpegPath = $ffmpeg->getFFmpeg();

        // yt-dlp expects the directory containing ffmpeg and ffprobe.
        $ffmpegDirectory = dirname($ffmpegPath);

        /*
         * Output example:
         *
         * 01 - Song One.mp3
         * 02 - Song Two.mp3
         * 03 - Song Three.mp3
         */
        $outputTemplate =
            $destination
            . DIRECTORY_SEPARATOR
            . '%(playlist_index)02d - %(title)s.%(ext)s';

        $command = sprintf(
            '%s --newline --yes-playlist -x --audio-format mp3 -o %s --ffmpeg-location %s %s',
            escapeshellarg($binaryPath),
            escapeshellarg($outputTemplate),
            escapeshellarg($ffmpegDirectory),
            escapeshellarg($url)
        );

        $logger("Starting YouTube playlist audio download...", 'info');
        $logger("Destination: {$destination}", 'info');
        $schedule->addLog("Starting YouTube playlist audio download...", 'info');
        $schedule->addLog("Destination: {$destination}", 'info');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            $logger("Error: Could not start yt-dlp.", 'error');
            $schedule->addLog("Error: Could not start yt-dlp.", 'error');
            return;
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            $changed = stream_select(
                $read,
                $write,
                $except,
                0,
                200000
            );

            if ($changed !== false) {
                foreach ($read as $pipe) {
                    while (($line = fgets($pipe)) !== false) {
                        $line = rtrim($line, "\r\n");

                        if ($line !== '') {
                            $logger($line, 'info');
                            $schedule->addLog($line, 'info');
                        }
                    }
                }
            }

            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }
        }

        // Flush remaining output.
        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            while (($line = fgets($pipe)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line !== '') {
                    $logger($line, 'info');
                    $schedule->addLog($line, 'info');
                }
            }

            fclose($pipe);
        }

        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            $logger("Error: Playlist audio download failed. Return code: {$returnCode}", 'error');
            $schedule->addLog("Error: Playlist audio download failed. Return code: {$returnCode}", 'error');
            return;
        }

        $logger(
            "Playlist audio download completed successfully.",
            'info'
        );
        $schedule->addLog( "Playlist audio download completed successfully.",
            'info');
    }


    function downloadAudio(string $url, string $destination, callable $logger, Schedule $schedule): void
    {

        if (!$url) {
            $logger("Error: No URL provided.", 'error');
            $schedule->addLog("Error: No URL provided.", 'error');
            return;
        }

        if (!$destination) {
            $logger("Error: No destination provided.", 'error');
            $schedule->addLog("Error: No destination provided.", 'error');
            return;
        }

        $destination = rtrim($destination, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . time();

        // Suppress PHP warnings.
        error_reporting(E_ERROR | E_PARSE);

        $binaryPath =
            \Simp\Pindrop\Modules\yt_dlp_downloader\src\binary\YTBinary::getBinaryPath();

        $ffmpeg = new Binary();

        $ffmpegPath = $ffmpeg->getFFmpeg();

        // yt-dlp accepts the directory containing ffmpeg and ffprobe.
        $ffmpegDirectory = dirname($ffmpegPath);

        /*
         * --newline makes yt-dlp output each progress update
         * on a new line instead of using carriage returns.
         *
         * --extract-audio extracts the audio.
         *
         * --audio-format mp3 converts the audio to MP3 using FFmpeg.
         */
        $command = sprintf(
            '%s --newline -x --audio-format mp3 -o %s --ffmpeg-location %s %s',
            escapeshellarg($binaryPath),
            escapeshellarg($destination . '.%(ext)s'),
            escapeshellarg($ffmpegDirectory),
            escapeshellarg($url)
        );

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            $logger("Error: Could not start yt-dlp.", 'error');
            $schedule->addLog("Error: Could not start yt-dlp.", 'error');

            return;
        }

        // We don't need stdin.
        fclose($pipes[0]);

        // Don't block while reading output.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        /*
         * Read yt-dlp output while it is running.
         */
        while (true) {

            $read = [
                $pipes[1],
                $pipes[2],
            ];

            $write = null;
            $except = null;

            $changed = stream_select(
                $read,
                $write,
                $except,
                0,
                200000
            );

            if ($changed !== false) {

                foreach ($read as $pipe) {

                    while (($line = fgets($pipe)) !== false) {

                        $line = rtrim($line, "\r\n");

                        if ($line !== '') {
                            $logger("Progress: $line", 'info');
                            $schedule->addLog("Progress: $line", 'info');
                        }
                    }
                }
            }

            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }
        }

        /*
         * Read anything remaining after yt-dlp exits.
         */
        foreach ([$pipes[1], $pipes[2]] as $pipe) {

            while (($line = fgets($pipe)) !== false) {

                $line = rtrim($line, "\r\n");

                if ($line !== '') {
                    $logger("Progress: $line", 'info');
                    $schedule->addLog("Progress: $line", 'info');
                }
            }

            fclose($pipe);
        }

        $return_var = proc_close($process);

        if ($return_var !== 0) {

            $logger("Error executing yt-dlp command. Return code: $return_var", 'error');
            $schedule->addLog("Error executing yt-dlp command. Return code: $return_var", 'error');

            return;
        }

        $logger("Audio download completed successfully.", 'info');
        $schedule->addLog("Audio download completed successfully.", 'info');
    }


}
