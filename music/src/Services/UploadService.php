<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\FileSystem\FileSystemService;

/**
 * UploadService
 *
 * Orchestrates a track upload: validates the file, moves it via core's
 * FileSystemService::uploadFile() (confirmed signature — see the
 * plugin's build plan doc for why this wraps core rather than reinventing
 * upload handling), records an audit row, and creates the track entry.
 *
 * Audio duration is NOT extracted server-side (that needs a library like
 * getID3, an extra Composer dependency this plugin deliberately doesn't
 * require — same "don't silently add a dependency" discipline as the
 * dev_console plugin's psy/psysh requirement, just resolved the other way
 * here: avoid the dependency entirely). The upload form extracts duration
 * client-side via a temporary <audio> element before submitting, and it
 * arrives here as a plain integer — validated for sanity, not re-derived.
 */
class UploadService
{
    private const ALLOWED_AUDIO_MIME = [
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav',
        'audio/ogg', 'audio/flac', 'audio/x-flac', 'audio/mp4', 'audio/aac',
    ];
    private const ALLOWED_IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_AUDIO_BYTES = 100 * 1024 * 1024;  // 100MB
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;    // 8MB

    public function __construct(
        protected FileSystemService $fileSystem,
        protected DatabaseService $database,
        protected TrackService $trackService,
        protected ArtistService $artistService,
        protected AlbumService $albumService
    ) {}

    /**
     * @param array $audioFile   raw $_FILES-shaped array for the audio upload
     * @param array|null $coverFile  raw $_FILES-shaped array for cover art, optional
     * @throws \InvalidArgumentException on validation failure — caller
     *   (UploadController) catches this and re-renders the form with the message.
     */
    public function uploadTrack(
        int $userId,
        string $username,
        int $artistId,
        ?int $albumId,
        string $title,
        int $durationSeconds,
        array $audioFile,
        ?array $coverFile,
        ?string $genre,
        ?string $lyrics
    ): int {
        $this->assertValidUpload($audioFile, self::ALLOWED_AUDIO_MIME, self::MAX_AUDIO_BYTES, 'audio');

        if ($durationSeconds <= 0 || $durationSeconds > 3600 * 4) {
            throw new \InvalidArgumentException('Could not determine a valid track duration. Try re-selecting the file.');
        }

        $artist = $this->artistService->find($artistId);
        if (!$artist || (int) $artist['owner_user_id'] !== $userId) {
            throw new \InvalidArgumentException('You can only upload tracks to an artist profile you own.');
        }

        if ($albumId !== null) {
            $album = $this->albumService->find($albumId);
            if (!$album || (int) $album['artist_id'] !== $artistId) {
                throw new \InvalidArgumentException('That album does not belong to this artist.');
            }
        }

        $audioExt = $this->extension($audioFile['name']);
        $audioDest = 'public://music/tracks/' . $this->uniqueSegment() . '.' . $audioExt;
        $audioInfo = $this->doUpload($audioFile, $audioDest, 'audio file');

        $coverUri = null;
        if ($coverFile && !empty($coverFile['name'])) {
            $this->assertValidUpload($coverFile, self::ALLOWED_IMAGE_MIME, self::MAX_IMAGE_BYTES, 'cover image');
            $coverExt = $this->extension($coverFile['name']);
            $coverDest = 'public://music/covers/' . $this->uniqueSegment() . '.' . $coverExt;
            $coverInfo = $this->doUpload($coverFile, $coverDest, 'cover image');
            $coverUri = $coverInfo['uri'];
        }

        $trackId = $this->trackService->create(
            $artistId,
            $albumId,
            $title,
            $audioInfo['uri'],
            $durationSeconds,
            $userId,
            $username,
            $coverUri,
            $genre,
            $lyrics
        );

        $this->recordUploadAudit($trackId, $userId, $audioFile, $audioInfo);
        $this->artistService->adjustTracksCount($artistId, 1);
        if ($albumId !== null) {
            $this->albumService->adjustTracksCount($albumId, 1);
        }

        return $trackId;
    }

    /**
     * Wraps FileSystemService::uploadFile() — which never throws, it
     * returns ['success' => false, 'message' => ...] on failure and nests
     * the actual file info one level down in ['data'][0] on success (not
     * a flat array — easy to miss, verified against the real core source
     * rather than assumed). Converts a failed upload into a thrown
     * exception so callers can't accidentally proceed with a DB row
     * pointing at a file that was never actually written.
     *
     * @throws \RuntimeException
     */
    private function doUpload(array $file, string $destinationUri, string $label): array
    {
        $result = $this->fileSystem->uploadFile($file, $destinationUri);

        if (empty($result['success']) || empty($result['data'][0]['uri'])) {
            $message = $result['message'] ?? 'unknown error';
            throw new \RuntimeException("Failed to upload {$label}: {$message}");
        }

        return $result['data'][0];
    }

    /** @throws \InvalidArgumentException */
    private function assertValidUpload(array $file, array $allowedMime, int $maxBytes, string $label): void
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException("Please choose a {$label} file.");
        }
        if (($file['size'] ?? 0) > $maxBytes) {
            throw new \InvalidArgumentException(ucfirst($label) . ' is too large (max ' . round($maxBytes / 1024 / 1024) . 'MB).');
        }

        // Trust finfo over the client-supplied MIME type — browsers/clients
        // can send an arbitrary Content-Type for a file input.
        $detected = @mime_content_type($file['tmp_name']) ?: ($file['type'] ?? '');
        if (!in_array($detected, $allowedMime, true)) {
            throw new \InvalidArgumentException("That doesn't look like a supported {$label} file type.");
        }
    }

    private function recordUploadAudit(int $trackId, int $userId, array $file, array $uploadResult): void
    {
        $this->database->table('music_uploads')->insert([
            'track_id'            => $trackId,
            'original_filename'   => $file['name'],
            'mime_type'           => $uploadResult['mime_type'] ?? (@mime_content_type($file['tmp_name']) ?: 'application/octet-stream'),
            'filesize'            => $uploadResult['size'] ?? (int) ($file['size'] ?? 0),
            'checksum_sha256'     => is_file($file['tmp_name']) ? hash_file('sha256', $file['tmp_name']) : null,
            'uploaded_by_user_id' => $userId,
        ]);
    }

    private function extension(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
    }

    private function uniqueSegment(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }
}
