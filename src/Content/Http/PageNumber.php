<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Http\Exception\NotFound;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The "?page=N" query parameter, shared by ContentController::index() and TermController::show().
 */
final class PageNumber
{
    public static function fromRequest(ServerRequestInterface $request): int
    {
        $page = $request->getQueryParams()['page'] ?? '1';
        if (!is_string($page) || preg_match('/^[1-9]\d{0,5}$/', $page) !== 1) {
            throw new NotFound('Invalid page number');
        }

        return (int) $page;
    }
}
