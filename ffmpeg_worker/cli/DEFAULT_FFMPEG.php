<?php

namespace Simp\Pindrop\Modules\ffmpeg_worker\cli;

use InvalidArgumentException;
use RuntimeException;
use Simp\Pindrop\Modules\ffmpeg_worker\binaries\Binary;
use SplFileInfo;

class DEFAULT_FFMPEG
{

    private static function binary(): Binary
    {
        return new Binary();
    }


    public static function getBinary(\CLIPrinter $printer, ...$values)
    {

        $printer->printLine("FFMPEG EXECUTABLE: " . self::binary()->getFFmpeg() . PHP_EOL . "FFPROBE EXECUTABLE: " .
            self::binary()->getFFprobe(), GREEN);
    }

    public static function getAudioMetadata(\CLIPrinter $printer, ...$values)
    {
        if (isset($values[1][2]) && $values[1][2] === '-f') {
            // process one audio file
            $audio_file = $values[1][3] ?? '';
            $audio_meta = self::extracteAudioMetadata($audio_file);

            $printer->printData($audio_meta ?? [], "Metadata for Audio file: " . pathinfo($audio_file, PATHINFO_FILENAME));
        } elseif (isset($values[1][2]) && $values[1][2] === '-ff') {
            // process more than one files
            $audio_files = array_slice($values[1] ?? [], 3, count($values[1]));

            foreach ($audio_files as $audio_file) {
                $audio_meta = self::extracteAudioMetadata($audio_file);
                $printer->printData($audio_meta, "Metadata for Audio file: " . pathinfo($audio_file, PATHINFO_FILENAME));
            }

        }

    }

    public static function extracteAudioMetadata(string $filepath)
    {
        $command = sprintf(
            self::binary()->getFFprobe() . ' -v quiet -show_format -show_streams -of json %s 2>&1',
            escapeshellarg($filepath)
        );

        $output = shell_exec($command);

        if ($output === null || $output === '') {
            throw new RuntimeException('FFprobe failed to read the MP3 metadata.');
        }

        $metadata = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'Invalid FFprobe JSON: ' . json_last_error_msg()
            );
        }

        return $metadata;

    }

    public static function extractAudioCoverImage(string $audioFile, string $outputFile): ?string
    {

        if (!is_file($audioFile)) {
            throw new InvalidArgumentException(
                "Audio file does not exist: {$audioFile}"
            );
        }

        $command = sprintf(
            self::binary()->getFFmpeg() . ' -y -v error -i %s -map 0:v:0 -frames:v 1 %s 2>&1',
            escapeshellarg($audioFile),
            escapeshellarg($outputFile)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($outputFile)) {
            return null;
        }

        return $outputFile;
    }

    public static function extractAudioImage(\CLIPrinter $printer, ...$values)
    {
        if (isset($values[1][2]) && $values[1][2] === '-f' && isset($values[1][4]) && $values[1][4] === '-d') {
            $audio_file = $values[1][3] ?? null;
            $destination = $values[1][5] ?? null;

            if (!empty($audio_file) && !empty($destination)) {
                $file = self::extractAudioCoverImage($audio_file, $destination);
                if ($file) {
                    $info = new SplFileInfo($file);

                    $printer->printData([
                        'file' => [
                            'name' => $info->getFilename(),
                            'path' => $info->getPathname(),
                            'size' => $info->getSize(),
                            'extension' => $info->getExtension(),
                        ],
                    ], 'Cover image for audio file: ' . $audio_file);
                }
            }
        }
    }

}
