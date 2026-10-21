<?php

declare(strict_types=1);

namespace AtarCms\Content\Markdown;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The path of the current page's markdown twin (EntryMarkdown): a request attribute ContentController sets on public
 * entry pages (never previews, archives, term pages, search or error pages). $this->markdownAlternate() prints it.
 */
final readonly class MarkdownTwin
{
    public function __construct(public string $path) {}

    public static function of(ServerRequestInterface $request): ?self
    {
        $twin = $request->getAttribute(self::class);

        return $twin instanceof self ? $twin : null;
    }

    /**
     * $request with $path as its twin's path; unchanged when $path is null.
     */
    public static function attach(ServerRequestInterface $request, ?string $path): ServerRequestInterface
    {
        return $path === null ? $request : $request->withAttribute(self::class, new self($path));
    }
}
