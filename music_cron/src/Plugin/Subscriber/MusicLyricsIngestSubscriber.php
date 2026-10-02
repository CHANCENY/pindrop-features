<?php

namespace Simp\Pindrop\Modules\music_cron\src\Plugin\Subscriber;

use Simp\Pindrop\Modules\cron\src\Plugin\Subscriber\ScheduleSubscriber;
use Simp\Pindrop\Modules\music_cron\src\Service\MusicIngestService;

/**
 * MusicLyricsIngestSubscriber
 *
 * CronDefinitionSubscriberInterface's constructor signature is fixed to
 * __construct(DatabaseService $databaseService) — CronManager instantiates
 * every subscriber that way (`new $class(...->get('database'))`), so this
 * class can't receive MusicIngestService via normal constructor injection
 * like a controller would. It pulls the service straight off the
 * container instead, the same pattern the cron plugin's own
 * TwigExtension::cronJob() uses for cron.manager.
 *
 * Ingestion itself is stateless with respect to which schedule triggered
 * it — there is one untrace/ folder, not one per schedule — so if more
 * than one due schedule happens to point at this subscriber in the same
 * run, the scan/import work happens exactly once and its log is then
 * replayed onto every due schedule's own log (so each schedule's
 * dashboard entry still shows what happened) before each is marked
 * finished() and gets its own next_run recalculated.
 */
class MusicLyricsIngestSubscriber extends ScheduleSubscriber
{
    public function name(): string
    {
        return 'Music lyrics ingest subscriber';
    }

    public function id(): string
    {
        return 'music.lyrics.ingest.subscriber';
    }

    public function runSchedules(array $schedules): string
    {
        if (empty($schedules)) {
            return '';
        }

        /** @var MusicIngestService $ingest */
        $ingest = \getAppContainer()->get('music_cron.ingest');

        $entries = [];
        $logger = function (string $message, string $type) use (&$entries) {
            $entries[] = [$message, $type];
        };

        foreach ($schedules as $schedule) {
            $schedule->addLog('Music ingest run started', 'start');
        }

        $stats = $ingest->runLyricsIngest($logger);

        foreach ($schedules as $schedule) {
            foreach ($entries as [$message, $type]) {
                $schedule->addLog($message, $type);
            }
            $schedule->finished();
        }

        return sprintf(
            'Music ingest: %d lyrics found, %d imported, %d, %d skipped, %d failed.',
            $stats['lyrics_found'],
            $stats['lyrics_imported'],
            $stats['lyrics_skipped'],
            $stats['lyrics_failed']
        );
    }
}
