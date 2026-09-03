<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music_cron\src\Service;

use RuntimeException;
use Simp\Pindrop\FileSystem\FileSystemService;

/**
 * StorageMover
 *
 * music's own UploadService::doUpload() wraps FileSystemService::uploadFile(),
 * but that method is built around a real HTTP $_FILES array — its README-
 * documented ['success'=>bool,'data'=>[0=>[...]]] shape is exactly what
 * UploadService relies on, and the only way this plugin has to reach that
 * same core API (cross-plugin table access is forbidden, but core services
 * are the framework's own public surface, not a plugin's).
 *
 * A file dropped in sites/default/files/music/albums/untrace never went
 * through an HTTP POST, so is_uploaded_file() on it will always be false.
 * If FileSystemService::uploadFile() internally leans on
 * move_uploaded_file() for its actual move step, calling it with a
 * synthetic $_FILES-shaped array pointing at a real on-disk path will
 * report failure rather than silently doing the wrong thing — that's the
 * scenario the fallback below exists for.
 *
 * UNVERIFIED ASSUMPTION (flag this the same way music's own README flags
 * its verified-vs-assumed API calls): the fallback treats "public://..."
 * as a real, registered PHP stream wrapper (simp/streamwrapper is a
 * declared Composer dependency of simp/pindrop) usable directly with
 * copy()/is_file(). Run `pindro music_cron:selftest` (see cli/) against a
 * real environment before relying on this in production — it reports
 * which of the two paths actually succeeded.
 */
class StorageMover
{
    public function __construct(protected FileSystemService $fileSystem)
    {
    }

    /**
     * Moves $localSourcePath (a real file already on disk) into
     * $destinationUri (a "public://..." storage URI) and deletes the
     * source on success. Returns the resolved URI + size + mime type.
     *
     * @throws RuntimeException if neither move strategy works — callers
     *   must treat this as "do not create a DB row for this file".
     */
    public function moveIntoStorage(string $localSourcePath, string $destinationUri, string $mimeType): array
    {
        if (!is_file($localSourcePath)) {
            throw new RuntimeException("Source file does not exist: {$localSourcePath}");
        }

        $size = filesize($localSourcePath) ?: 0;

        $viaUploadFile = $this->tryViaUploadFile($localSourcePath, $destinationUri, $mimeType, $size);
        if ($viaUploadFile !== null) {
            $this->deleteSource($localSourcePath);
            return $viaUploadFile;
        }

        $viaDirectCopy = $this->tryViaDirectCopy($localSourcePath, $destinationUri, $mimeType, $size);
        if ($viaDirectCopy !== null) {
            $this->deleteSource($localSourcePath);
            return $viaDirectCopy;
        }

        throw new RuntimeException(
            "Failed to move {$localSourcePath} into storage at {$destinationUri} " .
            "(both FileSystemService::uploadFile() and a direct stream copy failed)."
        );
    }

    /**
     * Attempt 1: go through core's sanctioned upload API with a synthetic
     * $_FILES-shaped array. Works if uploadFile()'s move step is a plain
     * copy/rename rather than move_uploaded_file(); returns null (not an
     * exception) on failure so the caller can fall through cleanly, since
     * uploadFile() itself never throws (see music's UploadService docblock).
     */
    private function tryViaUploadFile(string $sourcePath, string $destinationUri, string $mimeType, int $size): ?array
    {
        $synthetic = [
            'name'     => basename($sourcePath),
            'type'     => $mimeType,
            'tmp_name' => $sourcePath,
            'error'    => UPLOAD_ERR_OK,
            'size'     => $size,
        ];

        try {
            $result = $this->fileSystem->uploadFile($synthetic, $destinationUri);
        } catch (\Throwable) {
            return null;
        }

        if (empty($result['success']) || empty($result['data'][0]['uri'])) {
            return null;
        }

        $data = $result['data'][0];

        return [
            'uri'       => $data['uri'],
            'size'      => $data['size'] ?? $size,
            'mime_type' => $data['mime_type'] ?? $mimeType,
        ];
    }

    /**
     * Attempt 2 (fallback): treat the destination URI as a real,
     * registered PHP stream target and copy directly. See the class
     * docblock — this is the part that needs verifying against a real
     * environment.
     */
    private function tryViaDirectCopy(string $sourcePath, string $destinationUri, string $mimeType, int $size): ?array
    {
        try {
            if (!@copy($sourcePath, $destinationUri)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        if (!@is_file($destinationUri)) {
            return null;
        }

        return [
            'uri'       => $destinationUri,
            'size'      => $size,
            'mime_type' => $mimeType,
        ];
    }

    private function deleteSource(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
