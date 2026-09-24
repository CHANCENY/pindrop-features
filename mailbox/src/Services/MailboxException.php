<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Services;

class MailboxException extends \RuntimeException
{
    public function __construct(string $message, int $code = 400, public readonly string $reason = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
