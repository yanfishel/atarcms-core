<?php

declare(strict_types=1);

namespace AtarCms\Content\StructuredData;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Where the current page sits for search engines: its ancestors (the archive, the sections or the parent pages, root
 * first) and the page itself, each with its absolute URL. A request attribute set by the public entry and term
 * controllers (Breadcrumbs builds it, never on previews); StructuredData prints it as a BreadcrumbList when the page
 * has an ancestor.
 */
final readonly class PageTrail
{
    /**
     * @param list<array{name: string, url: string}> $items root first, the page last
     */
    public function __construct(public array $items) {}

    public static function of(ServerRequestInterface $request): ?self
    {
        $trail = $request->getAttribute(self::class);

        return $trail instanceof self ? $trail : null;
    }

    public static function attach(ServerRequestInterface $request, self $trail): ServerRequestInterface
    {
        return $request->withAttribute(self::class, $trail);
    }
}
