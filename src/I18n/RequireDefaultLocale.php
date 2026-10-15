<?php

declare(strict_types=1);

namespace AtarCms\I18n;

use AtarCms\Http\Exception\NotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware for pages that exist only in the default locale (admin, auth): 404 under a locale prefix.
 * LocalizedUrls also uses its presence to decide whether a route is localizable.
 */
final class RequireDefaultLocale implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $request->getAttribute(RequestLocale::class);
        if ($locale instanceof RequestLocale && $locale->prefixed) {
            throw new NotFound('This page is not available under a locale prefix');
        }

        return $handler->handle($request);
    }
}
