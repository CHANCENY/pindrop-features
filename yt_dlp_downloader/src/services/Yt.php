<?php

namespace Simp\Pindrop\Modules\yt_dlp_downloader\src\services;

use Simp\Pindrop\Modules\ffmpeg_worker\binaries\Binary;
use Simp\Pindrop\Modules\yt_dlp_downloader\src\binary\YTBinary;

class Yt
{
    protected static string $ytBinary;
    protected Binary $ffmpegBinary;
    protected string $ffmpegBinaryLocation;

    public function __construct()
    {
        self::$ytBinary = YTBinary::getBinaryPath();
        $this->ffmpegBinary = new Binary();

        $ffmpegPath = $this->ffmpegBinary->getFFmpeg();

        // yt-dlp expects the directory containing ffmpeg and ffprobe.
        $this->ffmpegBinaryLocation = dirname($ffmpegPath);
    }

    function collectMetadata(\CLIPrinter $printer, ...$values): void
    {

        $url = $values[1][3] ?? null;
        if (!$url) {
            $printer->printLine("Error: No URL provided.");
            return;
        }

        // surpase warnings when running the command
        error_reporting(E_ERROR | E_PARSE);
        $binaryPath = \Simp\Pindrop\Modules\yt_dlp_downloader\src\binary\YTBinary::getBinaryPath();
        $command = escapeshellcmd("$binaryPath --dump-json \"$url\"");

        // Execute the command and capture the output
        exec($command, $output, $return_var);

        if ($return_var !== 0) {
            $printer->printLine("Error executing yt-dlp command. Return code: $return_var");
            return;
        }

        // $output is array of json strings, we need to decode them
        $output = array_map(function ($line) {
            return json_decode($line, true);
        }, $output);
        // Output the metadata
        $printer->printData($output);
    }


    function downloadVideo(\CLIPrinter $printer, ...$values): void
    {
        $url = $values[1][3] ?? null;

        $destination = $values[1][5] ?? null;

        if (!$url) {
            $printer->printLine("Error: No URL provided.");
            return;
        }

        if (!$destination) {
            $printer->printLine("Error: No destination provided.");
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
         */
        $command = sprintf(
            '%s --newline -f %s --merge-output-format mp4 --remux-video mp4 -o %s --ffmpeg-location %s %s',
            escapeshellarg($binaryPath),
            escapeshellarg('bv*+ba/b'),
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
            return "Error: Could not start yt-dlp.";
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
                            system('clear'); // Clear the terminal screen
                            $printer->printLine($line, GREEN);
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
         * Read anything remaining after the process exits.
         */
        foreach ([$pipes[1], $pipes[2]] as $pipe) {

            while (($line = fgets($pipe)) !== false) {

                $line = rtrim($line, "\r\n");

                if ($line !== '') {
                    $printer->printLine($line, GREEN);
                }
            }

            fclose($pipe);
        }

        $return_var = proc_close($process);

        if ($return_var !== 0) {

            $printer->printLine(
                "Error executing yt-dlp command. Return code: $return_var"
            );

            return "Error executing yt-dlp command. Return code: $return_var";
        }

        $printer->printLine(
            "Download completed successfully."
        );
    }


    function downloadAudio(string $url, string $destination)
    {
        
        if (!$url) {
            return "Error: No URL provided.";
        }

        if (!$destination) {
            return "Error: No destination provided.";
        }
        }

        $destination = rtrim($destination, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . time();

        // Suppress PHP warnings.
        error_reporting(E_ERROR | E_PARSE);

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
            escapeshellarg(self::),
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
            return "Error: Could not start yt-dlp.";
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
                            system('clear'); // Clear the terminal screen
                            $printer->printLine($line, GREEN);
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
                    $printer->printLine($line, GREEN);
                }
            }

            fclose($pipe);
        }

        $return_var = proc_close($process);

        if ($return_var !== 0) {

            $printer->printLine(
                "Error executing yt-dlp command. Return code: $return_var"
            );

            return "Error executing yt-dlp command. Return code: $return_var";
        }

        $printer->printLine(
            "Audio download completed successfully."
        );
    }


    function downloadYoutubePlaylist(string $url, string $destination)
    {
        
        if (!$url) {
            return "Error: No playlist URL provided.";
        }

        if (!$destination) {
            return "Error: No destination provided.";
        }

        $destination = rtrim($destination, DIRECTORY_SEPARATOR);

        // Create destination directory if it does not exist.
        if (!is_dir($destination)) {
            if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
                return "Error: Could not create destination directory: {$destination}";
            }
        }

        // Suppress PHP warnings from the process handling.
        error_reporting(E_ERROR | E_PARSE);

        /*
         * Playlist output:
         *
         * 01 - Video title.mp3
         * 02 - Another video.mp3
         *
         * %(playlist_index)02d gives us zero-padded playlist indexes.
         */
        $outputTemplate =
            $destination
            . DIRECTORY_SEPARATOR
            . '%(playlist_index)02d - %(title)s.%(ext)s';

        $command = sprintf(
            '%s --newline --yes-playlist -x --audio-format mp3 -o %s --ffmpeg-location %s %s',
            escapeshellarg(self::$ytBinary),
            escapeshellarg($outputTemplate),
            escapeshellarg($this->ffmpegBinaryLocation),
            escapeshellarg($url)
        );

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
            return "Error: Could not start yt-dlp.";
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            return "Error: Playlist download failed. Return code: {$returnCode}";
        }

        return "YouTube playlist downloaded successfully.";
    }


    function downloadPlaylistAudios(string $url, string $destination): string
    {


        if (!$url) {
            return "Error: No playlist URL provided.";
        }

        if (!$destination) {
            return "Error: No destination provided.";
        }

        $destination = rtrim($destination, DIRECTORY_SEPARATOR);

        if (!is_dir($destination)) {
            if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
                return "Error: Could not create destination directory: {$destination}";
            }
        }

        // Suppress PHP warnings.
        error_reporting(E_ERROR | E_PARSE);

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
            escapeshellarg(self::$ytBinary),
            escapeshellarg($outputTemplate),
            escapeshellarg($this->ffmpegBinaryLocation),
            escapeshellarg($url)
        );

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
            return "Error: Could not start yt-dlp.";
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);


        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            return "Error: Playlist audio download failed. Return code: {$returnCode}";
        }
        return "Playlist audio download completed successfully.";
    }

}
