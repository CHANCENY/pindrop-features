<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Imap;

class ImapException extends \RuntimeException
{
    public const CONNECT = 'connect';
    public const TIMEOUT = 'timeout';
    public const IO = 'io';
    public const AUTH = 'auth';
    public const PROTOCOL = 'protocol';
    public const NO = 'no';        // server said NO to a command
    public const BAD = 'bad';      // server said BAD (we sent something malformed)
    public const UNSUPPORTED = 'unsupported';

    public function __construct(string $message, public readonly string $kind = self::PROTOCOL, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
