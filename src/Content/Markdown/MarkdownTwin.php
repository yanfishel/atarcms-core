<?php

declare(strict_types=1);

namespace AtarCms\Content\Markdown;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The path of the current page's markdown twin (EntryMarkdown): a request attribute ContentController sets on public
 * entry pages (never previews, archives, term pages, search or error pages). $this->markdownAlternate() prints it,
 * and ContentController sends it as a Link header (link()) wherever the helper would print it.
 */
final readonly class MarkdownTwin
{
    public function __construct(public string $path) {}

    /**
     * The Link header value: '</news/hello.md>; rel="alternate"; type="text/markdown"' (root-relative, like the
     * describedby link to /llms.txt).
     */
    public function link(): string
    {
        return '<' . $this->path . '>; rel="alternate"; type="text/markdown"';
    }

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
