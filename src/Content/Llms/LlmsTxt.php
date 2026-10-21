<?php

declare(strict_types=1);

namespace AtarCms\Content\Llms;

use AtarCms\Kernel\Config;

/**
 * What /llms.txt holds (llmstxt.org): LlmsContent's header, a pointer to the entry pages' markdown twins
 * (EntryMarkdown) and one to /llms-full.txt, then LlmsContent's sections as "## {label} ({locale})" with a markdown
 * link per public entry or term — the sitemap's URLs as a map an LLM reads in one request. After content.llms_limit
 * links (default 1000) the listing stops and names the sitemap.
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

        $limit = $this->config->int('content.llms_limit', self::DEFAULT_LIMIT);
        $count = 0;
        $current = null;
        foreach ($this->content->links() as $heading => $link) {
            if ($count === $limit) {
                return implode("\n", [...$lines, '', 'More content: ' . $base . '/sitemap.xml']) . "\n";
            }
            if ($heading !== $current) {
                $current = $heading;
                $lines[] = '';
                $lines[] = '## ' . $heading;
                $lines[] = '';
            }
            $lines[] = sprintf('- [%s](%s)', self::escape($link->title), $link->url);
            $count++;
        }

        return implode("\n", $lines) . "\n";
    }

    /** A title inside a markdown link: backslashes and square brackets escaped. */
    private static function escape(string $title): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $title);
    }
}
