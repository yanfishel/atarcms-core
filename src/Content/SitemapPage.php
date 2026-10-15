<?php

declare(strict_types=1);

namespace AtarCms\Content;

/**
 * One page of a sitemap index: whole translation groups of one routed collection ("entries") or taxonomy ("terms").
 */
final readonly class SitemapPage
{
    public const string ENTRIES = 'entries';

    public const string TERMS = 'terms';

    public function __construct(
        public string $kind,
        public string $source,
        public int $number,
    ) {}

    /**
     * The page's path, e.g. "/sitemap-entries-news-2.xml".
     */
    public function path(): string
    {
        return sprintf('/sitemap-%s-%s-%d.xml', $this->kind, $this->source, $this->number);
    }
}
