<?php

declare(strict_types=1);

namespace AtarCms\Content\Llms;

use AtarCms\Kernel\Config;

/**
 * What /sitemap.md holds (the markdown sitemap of Vercel's Agent Readability check): /llms.txt's map without its
 * LLM-specific lines — "# {site name} — sitemap", LlmsContent's description and language lines, then its sections
 * with a "- [title](URL)" line per public entry or term, in /llms.txt's order and up to its limit
 * (content.llms_limit), after which it names /sitemap.xml.
 */
final readonly class MarkdownSitemap
{
    public function __construct(
        private LlmsContent $content,
        private Config $config,
    ) {}

    public function render(): string
    {
        return implode("\n", [
            ...$this->content->header(' — sitemap'),
            ...$this->content->listing($this->config->int('content.llms_limit', LlmsTxt::DEFAULT_LIMIT)),
        ]) . "\n";
    }
}
