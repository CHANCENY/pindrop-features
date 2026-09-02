<?php

namespace Simp\Pindrop\Modules\ffmpeg_worker\api;

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
}
