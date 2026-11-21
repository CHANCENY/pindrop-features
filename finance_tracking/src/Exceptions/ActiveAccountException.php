<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\Exceptions;

use Throwable;

class ActiveAccountException extends \Exception
{
    public function __construct(string $message = "", int $code = 0, Throwable|null $previous = null)
    {
        parent::__construct("Account associated with user is not set yet", 10001, $previous);
    }
}