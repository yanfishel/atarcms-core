<?php

declare(strict_types=1);

namespace AtarCms\Http\Exception;

/**
 * A request no form of the site sends (a crafted post).
 */
final class BadRequest extends HttpException
{
    public function statusCode(): int
    {
        return 400;
    }
}
