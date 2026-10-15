<?php

declare(strict_types=1);

namespace AtarCms\Http\Security;

use AtarCms\Http\Exception\CsrfTokenMismatch;
use AtarCms\Http\Routing\RouteMatch;
use AtarCms\Http\Session\Session;
use AtarCms\Kernel\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Unsafe methods need the session's synchronizer token (CsrfToken). A public form (Route::publicForm(), no session)
 * cannot carry one: its posts are refused when the browser marks them cross-site (Sec-Fetch-Site) or sends another
 * Origin than app.url's, and its controller checks the form's own signed token (PublicFormToken).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Config $config) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            if (RouteMatch::of($request)?->route->publicForm !== null) {
                $this->checkPublicForm($request);

                return $handler->handle($request);
            }
            $candidate = $request->getHeaderLine(CsrfToken::HEADER);
            if ($candidate === '') {
                $body = $request->getParsedBody();
                $candidate = is_array($body) ? ($body[CsrfToken::FIELD] ?? null) : null;
            }
            if (!CsrfToken::matches(Session::of($request), $candidate)) {
                throw new CsrfTokenMismatch('CSRF token missing or invalid');
            }
        }

        return $handler->handle($request);
    }

    private function checkPublicForm(ServerRequestInterface $request): void
    {
        if (strtolower($request->getHeaderLine('Sec-Fetch-Site')) === 'cross-site') {
            throw new CsrfTokenMismatch('A public form was posted from another site');
        }
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && self::origin($origin) !== self::origin($this->config->appUrl())) {
            throw new CsrfTokenMismatch(sprintf('A public form was posted from the origin "%s"', $origin));
        }
    }

    /**
     * "scheme://host:port" in lower case with the scheme's default port filled in ("null" and broken values stay
     * unequal to any site). The site must answer only on its app.url host (redirect www. or other names there): a page
     * served under another host posts with that host as its Origin and is refused.
     */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'invalid:' . $url;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0));

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }
}
