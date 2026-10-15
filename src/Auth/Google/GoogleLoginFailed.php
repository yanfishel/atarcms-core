<?php

declare(strict_types=1);

namespace AtarCms\Auth\Google;

use AtarCms\Auth\LoginFailure;
use RuntimeException;
use Throwable;

final class GoogleLoginFailed extends RuntimeException
{
    public function __construct(
        public readonly LoginFailure $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
