<?php

declare(strict_types=1);

namespace AtarCms\Http\Exception;

use RuntimeException;

abstract class HttpException extends RuntimeException
{
    abstract public function statusCode(): int;

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [];
    }
}
