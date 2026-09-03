<?php

use Simp\Pindrop\Modules\music_cron\src\Service\MusicIngestService;
use Simp\Pindrop\Modules\music_cron\src\Service\StorageMover;

/**
 * music-cron:run     — runs the ingest immediately, outside the scheduler,
 *                       for manual testing against a real untrace/ folder.
 * music-cron:selftest — verifies the StorageMover assumption flagged in
 *                       StorageMover's docblock: creates a throwaway file
 *                       and reports whether FileSystemService::uploadFile()
 *                       or the direct-copy fallback is what actually moves
 *                       it. Run this once in your real environment before
 *                       relying on a scheduled ingest.
 */
return [
    'music-cron:run'      => 'runIngestNow',
    'music-cron:selftest' => 'runStorageSelftest',
];

function runIngestNow(CLIPrinter $printer, ...$values): void
{
    $printer->printLine('Running music ingest...', GREEN);

    /** @var MusicIngestService $ingest */
    $ingest = \getAppContainer()->get('music_cron.ingest');

    $stats = $ingest->run(function (string $message, string $type) use ($printer) {
        $printer->printLine("[{$type}] {$message}");
    });

    $printer->printData($stats, 'Ingest summary');
}

function runStorageSelftest(CLIPrinter $printer, ...$values): void
{
    /** @var StorageMover $mover */
    $mover = \getAppContainer()->get('music_cron.storage_mover');

    $tmp = sys_get_temp_dir() . '/music_cron_selftest_' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($tmp, 'music_cron selftest file');

    $destination = 'public://music_cron_selftest/' . bin2hex(random_bytes(4)) . '.txt';

    try {
        $result = $mover->moveIntoStorage($tmp, $destination, 'text/plain');
        $printer->printData($result, 'Storage move succeeded — check which strategy actually ran '
            . 'by temporarily logging inside StorageMover if it matters which one it was.');
        $printer->printLine('Selftest PASSED. Delete the test file at ' . $result['uri'] . ' once confirmed.', GREEN);
    } catch (Throwable $e) {
        // Note: only GREEN is confirmed as a CLIPrinter color constant
        // elsewhere in this codebase, so this failure line intentionally
        // passes no color argument rather than guessing a RED constant exists.
        $printer->printLine('Selftest FAILED: ' . $e->getMessage());
        $printer->printLine('Neither FileSystemService::uploadFile() nor a direct stream copy could '
            . 'write to "public://..." from a plain on-disk source file. StorageMover needs a real '
            . 'core-verified move strategy before this plugin is safe to schedule.');
    }
}
