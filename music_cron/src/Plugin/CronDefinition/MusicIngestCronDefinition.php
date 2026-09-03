<?php

namespace Simp\Pindrop\Modules\music_cron\src\Plugin\CronDefinition;

use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionInterface;

/**
 * Registers the "music_ingest" category with the cron admin UI
 * (cron.create -> create_job.html.twig lists definitions by key/name).
 * An admin creates a `schedulers` row under this category, then a
 * `scheduler_jobs` row (cron expression + subscriber) pointing at
 * MusicIngestSubscriber to actually run it.
 */
class MusicIngestCronDefinition implements CronDefinitionInterface
{
    public function key(): string
    {
        return 'music_ingest';
    }

    public function name(): string
    {
        return 'Music Album Ingest';
    }

    public function getSubscribers(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Scans sites/default/files/music/albums/untrace for dropped album '
            . 'folders, reads real audio metadata via ffmpeg_worker, creates the '
            . 'matching artist/album/track records through the music plugin, moves '
            . 'each file into permanent storage, and clears imported album folders.';
    }
}
