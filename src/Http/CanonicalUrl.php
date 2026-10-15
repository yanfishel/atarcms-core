<?php

declare(strict_types=1);

namespace AtarCms\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The absolute canonical URL of the current page: a request attribute set by the public controllers of the pages
 * that have one (entries, archives and term pages; never previews, search or error pages). $this->canonical()
 * prints it, and the social meta tags only appear on pages that have one.
 */
final readonly class CanonicalUrl
{
    public function __construct(public string $url) {}

    public static function of(ServerRequestInterface $request): ?self
    {
        $canonical = $request->getAttribute(self::class);

        return $canonical instanceof self ? $canonical : null;
    }

    /**
     * $request with $url as its canonical URL; unchanged when $url is null.
     */
    public static function attach(ServerRequestInterface $request, ?string $url): ServerRequestInterface
    {
        return $url === null ? $request : $request->withAttribute(self::class, new self($url));
    }
}
