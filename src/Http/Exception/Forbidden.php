<?php

declare(strict_types=1);

namespace AtarCms\Http\Exception;

final class Forbidden extends HttpException
{
    public function statusCode(): int
    {
        return 403;
    }
}
