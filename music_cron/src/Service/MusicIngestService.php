<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music_cron\src\Service;

use Simp\Pindrop\Modules\ffmpeg_worker\api\SongsApiStandard;
use Simp\Pindrop\Modules\music\src\Services\AlbumService;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\TrackService;
use Throwable;

/**
 * MusicIngestService
 *
 * Bulk-imports album folders dropped in
 * sites/default/files/music/albums/untrace into the music plugin's own
 * tables, using ONLY music's public service API (ArtistService/
 * AlbumService/TrackService) — never touching music_* tables directly,
 * per core's cross-plugin isolation rule (DatabasePermissionGuard /
 * PluginTableRegistry).
 *
 * Layout expected under untrace/:
 *   untrace/<Album Folder Name>/<track file>.mp3, ...
 *   untrace/<Album Folder Name>/<track file>.mp3, ...
 *
 * Design decisions (flagged explicitly, same discipline as music's own
 * "Known simplifications" section):
 *
 * - Artist/album identity comes from each track's own ffprobe tags
 *   (format.tags.artist / format.tags.album), NOT the folder name — tags
 *   are per-track ground truth; the folder name is only a fallback label
 *   when a file has no usable tags at all. A folder is assumed to belong
 *   to a single artist; a track tagged with a different artist than the
 *   first successfully-read track in the same folder is skipped with a
 *   'warn' log rather than silently mixed in or used to fork the album.
 * - Imported artists/albums/tracks are owned by a fixed "house" account
 *   (id 0, username "system") — this mirrors music_artists.owner_user_id's
 *   own column comment: "0 = house/admin-managed".
 * - A track already present for the resolved artist+slug is treated as
 *   already-imported and skipped (not re-created, not re-moved) — this is
 *   a title-based dedupe, not a checksum-based one; a re-drop of a
 *   different recording under the same title will not be detected as new.
 * - An album folder is deleted only once every file inside it has either
 *   been imported or explicitly skipped as already-imported. Any file
 *   that fails (unreadable audio, move failure, artist-mismatch skip via
 *   'warn') is left in place and the folder is left for the next run —
 *   so a single bad file never loses progress on the rest of the album,
 *   and the untrace root only ends up "cleared" as a side effect of every
 *   dropped album eventually succeeding, not as an unconditional wipe.
 * - Only one cover image is extracted per album (from the first track
 *   with embedded art) and used as both music_albums.cover_url and each
 *   track's implicit fallback — matching the schema comment that a null
 *   track cover_url falls back to the album cover client-side. Per-track
 *   cover extraction is not attempted.
 */
class MusicIngestService
{
    private const HOUSE_USER_ID = 0;
    private const HOUSE_USERNAME = 'system';

    private const AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac'];

    private const MIME_BY_EXTENSION = [
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'flac' => 'audio/flac',
        'm4a'  => 'audio/mp4',
        'aac'  => 'audio/aac',
    ];

    public function __construct(
        protected ArtistService $artistService,
        protected AlbumService $albumService,
        protected TrackService $trackService,
        protected StorageMover $storageMover
    ) {
    }

    /**
     * @param callable(string $message, string $type): void $log
     *   $type must be one of Schedule::addLog()'s allowed values:
     *   start|debug|warn|error|ok|info.
     * @return array{albums_found:int, albums_imported:int, tracks_imported:int, tracks_skipped:int, tracks_failed:int}
     */
    public function run(callable $log): array
    {
        $stats = [
            'albums_found'    => 0,
            'albums_imported' => 0,
            'tracks_imported' => 0,
            'tracks_skipped'  => 0,
            'tracks_failed'   => 0,
        ];

        $untraceRoot = $this->untraceRoot();

        if (!is_dir($untraceRoot)) {
            $log("Untrace folder does not exist: {$untraceRoot}", 'info');
            return $stats;
        }

        $albumFolders = $this->listSubdirectories($untraceRoot);

        if (empty($albumFolders)) {
            $log("No album folders found in untrace.", 'info');
            return $stats;
        }

        foreach ($albumFolders as $albumPath) {
            $stats['albums_found']++;
            $folderResult = $this->importAlbumFolder($albumPath, $log, $stats);

            if ($folderResult) {
                $stats['albums_imported']++;
            }
        }

        return $stats;
    }

    /**
     * Imports a single album folder. Returns true if the folder ended up
     * fully processed and was removed, false if it was left in place for
     * retry (one or more files failed/were skipped for a reason other
     * than "already imported").
     */
    private function importAlbumFolder(string $albumPath, callable $log, array &$stats): bool
    {
        $folderName = basename($albumPath);
        $log("Scanning album folder '{$folderName}'", 'start');

        $trackFiles = $this->listAudioFiles($albumPath);
        if (empty($trackFiles)) {
            $log("Album folder '{$folderName}' has no recognized audio files — leaving it in place.", 'warn');
            return false;
        }

        // Probe every file up-front so we resolve artist/album identity
        // from real tags before creating anything.
        $probed = [];
        foreach ($trackFiles as $filePath) {
            $meta = $this->probe($filePath, $log);
            if ($meta !== null) {
                $probed[] = ['path' => $filePath, 'meta' => $meta];
            }
        }

        if (empty($probed)) {
            $log("No file in '{$folderName}' could be read by ffprobe — leaving it in place.", 'error');
            $stats['tracks_failed'] += count($trackFiles);
            return false;
        }

        $firstTags = $probed[0]['meta']['format']['tags'] ?? [];
        $artistName = trim((string) ($firstTags['artist'] ?? ''));
        if (str_contains($artistName, '/')) {
            $artistName = trim(explode('/', $artistName)[0]);
        }
        
        if ($artistName === '') {
            $log("Album folder '{$folderName}': no artist tag on the first readable track — cannot import without a known artist.", 'error');
            return false;
        }

        $albumTitle = trim((string) ($firstTags['album'] ?? '')) ?: $folderName;
        $releaseDate = $this->normalizeDate((string) ($firstTags['date'] ?? ''));

        $artistId = $this->resolveArtist($artistName, $log);
        $albumId = $this->resolveAlbum($artistId, $albumTitle, $releaseDate, $probed, $log);

        $allHandled = true;
        $importedAny = false;

        foreach ($probed as $entry) {
            $filePath = $entry['path'];
            $meta = $entry['meta'];
            $tags = $meta['format']['tags'] ?? [];
            $trackArtist = trim((string) ($tags['artist'] ?? ''));
            if(str_contains($trackArtist, '/')) {
                $trackArtist = trim(explode('/', $trackArtist)[0]);
            }

            if ($trackArtist !== '' && $this->slugify($trackArtist) !== $this->slugify($artistName)) {
                dd($trackArtist, $artistName);
                $log("Skipping '" . basename($filePath) . "': tagged artist '{$trackArtist}' does not match this album's artist '{$artistName}'.", 'warn');
                $allHandled = false;
                continue;
            }

            $outcome = $this->importTrack($artistId, $albumId, $filePath, $meta, $log);

            if ($outcome === 'imported') {
                $stats['tracks_imported']++;
                $importedAny = true;
            } elseif ($outcome === 'skipped') {
                $stats['tracks_skipped']++;
            } else {
                $stats['tracks_failed']++;
                $allHandled = false;
            }
        }

        if ($importedAny) {
            $log("Imported tracks into album '{$albumTitle}' by '{$artistName}'.", 'ok');
        }

        if ($allHandled) {
            $this->removeFolderIfEmpty($albumPath, $log);
            return true;
        }

        $log("Album folder '{$folderName}' left in place — one or more files were not handled.", 'warn');
        return false;
    }

    private function resolveArtist(string $artistName, callable $log): int
    {
        $slug = $this->slugify($artistName);
        $existing = $this->artistService->findBySlug($slug);
        if ($existing) {
            return (int) $existing['id'];
        }

        $id = $this->artistService->create(self::HOUSE_USER_ID, self::HOUSE_USERNAME, $artistName);
        $log("Created artist '{$artistName}'.", 'info');
        return $id;
    }

    /** @param array $probed list of ['path'=>string,'meta'=>array] for this album, used for cover extraction */
    private function resolveAlbum(int $artistId, string $albumTitle, ?string $releaseDate, array $probed, callable $log): int
    {
        $slug = $this->slugify($albumTitle);
        $existing = $this->albumService->findByArtistAndSlug($artistId, $slug);
        if ($existing) {
            return (int) $existing['id'];
        }

        $coverUri = $this->extractAlbumCover($probed, $log);

        $id = $this->albumService->create($artistId, $albumTitle, 'album', $coverUri, $releaseDate);
        $log("Created album '{$albumTitle}'.", 'info');
        return $id;
    }

    /** @param array $probed list of ['path'=>string,'meta'=>array] */
    private function extractAlbumCover(array $probed, callable $log): ?string
    {
        foreach ($probed as $entry) {
            $hasEmbeddedArt = false;
            foreach ($entry['meta']['streams'] ?? [] as $stream) {
                if (($stream['codec_type'] ?? '') === 'video' && !empty($stream['disposition']['attached_pic'])) {
                    $hasEmbeddedArt = true;
                    break;
                }
            }
            if (!$hasEmbeddedArt) {
                continue;
            }

            $tmpImage = sys_get_temp_dir() . '/music_cron_cover_' . bin2hex(random_bytes(6)) . '.jpg';

            try {
                $api = new SongsApiStandard();
                $extracted = $api->extractAudioCoverImage($entry['path'], $tmpImage);
            } catch (Throwable $e) {
                $log("Cover extraction threw for '" . basename($entry['path']) . "': " . $e->getMessage(), 'warn');
                continue;
            }

            if ($extracted === null || !is_file($tmpImage)) {
                continue;
            }

            $destination = 'public://music/covers/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jpg';

            try {
                $moved = $this->storageMover->moveIntoStorage($tmpImage, $destination, 'image/jpeg');
                return $moved['uri'];
            } catch (Throwable $e) {
                $log("Failed to store extracted cover image: " . $e->getMessage(), 'warn');
                return null;
            }
        }

        return null;
    }

    /** @return 'imported'|'skipped'|'failed' */
    private function importTrack(int $artistId, int $albumId, string $filePath, array $meta, callable $log): string
    {
        $tags = $meta['format']['tags'] ?? [];
        $filename = basename($filePath);

        $trackNumber = $this->extractTrackNumber($filename);
        $title = trim((string) ($tags['title'] ?? '')) ?: $this->titleFromFilename($filename);

        $existing = $this->trackService->findByArtistAndSlug($artistId, $this->slugify($title));
        if ($existing) {
            $log("Track '{$title}' already imported for this artist — skipping (file left for manual review).", 'info');
            return 'skipped';
        }

        $duration = $this->extractDuration($meta);
        if ($duration <= 0) {
            $log("Could not determine a valid duration for '{$filename}' — skipping.", 'error');
            return 'failed';
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) ?: 'mp3';
        $mimeType = self::MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream';
        $destination = 'public://music/tracks/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;

        try {
            $moved = $this->storageMover->moveIntoStorage($filePath, $destination, $mimeType);
        } catch (Throwable $e) {
            $log("Failed to move '{$filename}' into storage: " . $e->getMessage(), 'error');
            return 'failed';
        }

        $genre = trim((string) ($tags['genre'] ?? '')) ?: null;

        $this->trackService->create(
            $artistId,
            $albumId,
            $title,
            $moved['uri'],
            $duration,
            self::HOUSE_USER_ID,
            self::HOUSE_USERNAME,
            null, // cover_url — falls back to the album cover client-side
            $genre,
            null, // lyrics — not available from ffprobe tags
            $trackNumber
        );

        $this->artistService->adjustTracksCount($artistId, 1);
        $this->albumService->adjustTracksCount($albumId, 1);

        $log("Imported track '{$title}'.", 'ok');
        return 'imported';
    }

    private function probe(string $filePath, callable $log): ?array
    {
        try {
            $api = new SongsApiStandard();
            $meta = $api->extracteAudioMetadata($filePath);
        } catch (Throwable $e) {
            $log("ffprobe failed on '" . basename($filePath) . "': " . $e->getMessage(), 'error');
            return null;
        }

        if (empty($meta['format'])) {
            $log("ffprobe returned no usable format data for '" . basename($filePath) . "'.", 'error');
            return null;
        }

        return $meta;
    }

    private function extractDuration(array $meta): int
    {
        $duration = $meta['format']['duration'] ?? null;

        if ($duration === null) {
            foreach ($meta['streams'] ?? [] as $stream) {
                if (($stream['codec_type'] ?? '') === 'audio' && isset($stream['duration'])) {
                    $duration = $stream['duration'];
                    break;
                }
            }
        }

        return $duration !== null ? (int) round((float) $duration) : 0;
    }

    private function extractTrackNumber(string $filename): ?int
    {
        if (preg_match('/^(\d{1,3})[\.\-_\s]+/', $filename, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    private function titleFromFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/^\d{1,3}[\.\-_\s]+/', '', $name) ?? $name;
        return trim($name) ?: $filename;
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        if (preg_match('/^\d{4}$/', $date)) {
            return $date . '-01-01';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date, $m)) {
            return substr($m[0], 0, 10);
        }
        return null;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? substr($text, 0, 180) : 'item';
    }

    /** @return string[] absolute paths of immediate subdirectories */
    private function listSubdirectories(string $path): array
    {
        $entries = @scandir($path) ?: [];
        $dirs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $dirs[] = $full;
            }
        }
        return $dirs;
    }

    /** @return string[] absolute paths of recognized audio files directly inside $path */
    private function listAudioFiles(string $path): array
    {
        $entries = @scandir($path) ?: [];
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (is_file($full) && in_array($ext, self::AUDIO_EXTENSIONS, true)) {
                $files[] = $full;
            }
        }
        return $files;
    }

    private function removeFolderIfEmpty(string $albumPath, callable $log): void
    {
        $remaining = array_diff(@scandir($albumPath) ?: [], ['.', '..']);
        if (!empty($remaining)) {
            $log("Album folder '" . basename($albumPath) . "' still has unhandled files — not removing it.", 'warn');
            return;
        }

        if (@rmdir($albumPath)) {
            $log("Cleared album folder '" . basename($albumPath) . "' from untrace.", 'ok');
        } else {
            $log("Album folder '" . basename($albumPath) . "' was empty but could not be removed.", 'warn');
        }
    }

    /**
     * ASSUMPTION (see modules/cron/cli/bacground.php for the same
     * pattern): project root is four directories up from this file
     * (src/Service -> src -> music_cron -> modules -> root). Adjust here
     * if this plugin's own folder ever moves.
     */
    private function untraceRoot(): string
    {
        $root = dirname(__DIR__, 4);
        return $root . '/sites/default/files/music/albums/untrace';
    }
}
