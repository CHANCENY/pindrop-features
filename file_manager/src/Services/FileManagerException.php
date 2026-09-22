<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\file_manager\src\Services;

/**
 * Thrown for every expected failure (bad path, denied, conflict, ...).
 * The exception code doubles as the HTTP status the controller returns;
 * $reason is a short machine-readable tag the browser can react to (e.g. "csrf").
 */
class FileManagerException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 400,
        ?\Throwable $previous = null,
        public readonly string $reason = ''
    ) {
        parent::__construct($message, $code, $previous);
    }
}
