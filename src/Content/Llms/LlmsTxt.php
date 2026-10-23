<?php

declare(strict_types=1);

namespace AtarCms\Content\Llms;

use AtarCms\Kernel\Config;

/**
 * What /llms.txt holds (llmstxt.org): LlmsContent's header, a pointer to the entry pages' markdown twins
 * (EntryMarkdown), one to /llms-full.txt and one to /sitemap.md, then LlmsContent's sections as
 * "## {label} ({locale})" with a markdown link per public entry or term — the sitemap's URLs as a map an LLM reads in
 * one request. After content.llms_limit links (default 1000) the listing stops and names the sitemap.
 */
final readonly class LlmsTxt
{
    public const int DEFAULT_LIMIT = 1000;

    public function __construct(
        private LlmsContent $content,
        private Config $config,
    ) {}

    public function render(): string
    {
        $base = $this->config->appUrl();
        $lines = [...$this->content->header(), ''];
        // The entry pages' markdown twins (EntryMarkdown, ContentModule::registerRoutes()).
        $lines[] = "Append .md to an entry's URL for a markdown version.";
        $lines[] = '';
        $lines[] = 'The full text of the entry pages: ' . $base . '/llms-full.txt';
        $lines[] = '';
        $lines[] = 'A markdown sitemap: ' . $base . '/sitemap.md';

        return implode("\n", [...$lines, ...$this->content->listing($this->config->int('content.llms_limit', self::DEFAULT_LIMIT))]) . "\n";
    }
}
