<?php

declare(strict_types=1);

namespace AtarCms\Http\Exception;

final class NotFound extends HttpException
{
    public function statusCode(): int
    {
        return 404;
    }
}
