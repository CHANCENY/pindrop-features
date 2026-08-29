<?php

/**
 * provide quick start ffmpeg commands
 */

return [
    'ff:bin' => 'Simp\Pindrop\Modules\ffmpeg_worker\cli\DEFAULT_FFMPEG::getBinary',
    'ff:audio:meta' => 'Simp\Pindrop\Modules\ffmpeg_worker\cli\DEFAULT_FFMPEG::getAudioMetadata',
    'ff:audio:cover' => 'Simp\Pindrop\Modules\ffmpeg_worker\cli\DEFAULT_FFMPEG::extractAudioImage'
];

