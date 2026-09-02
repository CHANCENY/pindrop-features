<?php

namespace Simp\Pindrop\Modules\ffmpeg_worker\api;

use InvalidArgumentException;
use RuntimeException;
use Simp\Pindrop\Modules\ffmpeg_worker\binaries\Binary;

class SongsApiStandard
{
    protected Binary $binary;

    public function __construct()
    {
        $this->binary = new Binary();
    }

    public function extracteAudioMetadata(string $filepath)
    {
        $command = sprintf(
            $this->binary->getFFprobe() . ' -v quiet -show_format -show_streams -of json %s 2>&1',
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

    public function extractAudioCoverImage(string $audioFile, string $outputFile): ?string
    {

        if (!is_file($audioFile)) {
            throw new InvalidArgumentException(
                "Audio file does not exist: {$audioFile}"
            );
        }

        $command = sprintf(
            $this->binary->getFFmpeg() . ' -y -v error -i %s -map 0:v:0 -frames:v 1 %s 2>&1',
            escapeshellarg($audioFile),
            escapeshellarg($outputFile)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($outputFile)) {
            return null;
        }

        return $outputFile;
    }
}
