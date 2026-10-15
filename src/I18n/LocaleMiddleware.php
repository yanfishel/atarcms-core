<?php

declare(strict_types=1);

namespace AtarCms\I18n;

use AtarCms\Http\RequestPath;
use AtarCms\Http\Responses;
use AtarCms\Http\SafeRedirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the locale from a literal /{locale} path prefix and hides the prefix from the router.
 * Runs outside ErrorMiddleware, so it must never throw.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Locales $locales,
        private readonly Responses $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = RequestPath::of($request);
        $code = $this->prefixLocale($path);
        $default = $this->locales->default();
        if ($code === null) {
            return $handler->handle($request->withAttribute(RequestLocale::class, new RequestLocale($default, false)));
        }

        $prefix = '/' . $code;
        $rest = substr($path->raw, strlen($prefix));
        $isRead = in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true);
        if ($isRead && ($code === $default || $rest === '/')) {
            $target = $code === $default ? SafeRedirect::sanitize($rest === '' ? '/' : $rest, '/') : $prefix;
            $query = $request->getUri()->getQuery();

            return $this->responses->redirect($target . ($query === '' ? '' : '?' . $query), 301);
        }
        if ($code === $default) {
            return $handler->handle($request->withAttribute(RequestLocale::class, new RequestLocale($default, false)));
        }

        return $handler->handle($request
            ->withAttribute(RequestLocale::class, new RequestLocale($code, true))
            ->withAttribute(RequestPath::class, $path->withoutPrefix($prefix)));
    }

    private function prefixLocale(RequestPath $path): ?string
    {
        if (!$path->valid || preg_match('~^/([a-z0-9-]+)(?:/|$)~', $path->raw, $matches) !== 1) {
            return null;
        }

        return $this->locales->isSupported($matches[1]) ? $matches[1] : null;
    }
}
