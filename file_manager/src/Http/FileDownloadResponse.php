<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\file_manager\src\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a file in constant memory.
 *
 * Why not BinaryFileResponse? Pindrop's RouteProvider post-processes every
 * response with str_starts_with($response->getContent(), ...). BinaryFileResponse
 * returns false from getContent(), which is a TypeError under strict_types.
 * This class returns '' from getContent() (so that code path is a no-op) and
 * streams the real bytes in sendContent().
 */
class FileDownloadResponse extends Response
{
    public function __construct(private readonly string $path, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);
    }

    public function getContent(): string|false
    {
        return '';
    }

    public function sendContent(): static
    {
        $fh = @fopen($this->path, 'rb');
        if ($fh === false) {
            return $this;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @set_time_limit(0);
        while (!feof($fh)) {
            $chunk = fread($fh, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fh);
        return $this;
    }
}
