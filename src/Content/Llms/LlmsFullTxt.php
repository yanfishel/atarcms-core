<?php

declare(strict_types=1);

namespace AtarCms\Content\Llms;

use AtarCms\Content\Markdown\EntryMarkdown;
use AtarCms\Kernel\Config;

/**
 * What /llms-full.txt holds (the llms.txt convention of documentation sites): LlmsContent's header and a line naming
 * /llms.txt, then the public entry pages in /llms.txt's order, each as its markdown twin (EntryMarkdown, rendered
 * with the ancestors its sitemap chunk loaded) with "Source: {absolute URL}" under its "# {title}". Blocks are
 * separated by "---". The entries keep their twin's headings and there are no section headings: every block is a
 * document of its own, like the twin it copies. Terms have no markdown and are left out (/llms.txt lists them).
 * After content.llms_full_limit entries (default 100), or once the text passes MAX_BYTES, it stops and names
 * /llms.txt.
 */
final readonly class LlmsFullTxt
{
    /**
     * Each entry costs a few queries (translations, terms, media, relations): a hundred keeps the response well under
     * a second on a small VPS and is the whole of a typical small site.
     */
    public const int DEFAULT_LIMIT = 100;

    /** About 250k tokens: more than an LLM's context takes in one read. */
    public const int MAX_BYTES = 1_048_576;

    public function __construct(
        private LlmsContent $content,
        private EntryMarkdown $markdown,
        private Config $config,
    ) {}

    public function render(): string
    {
        $base = $this->config->appUrl();
        $header = [...$this->content->header(), '', "The full text of the site's entry pages, one after another. The map of every page and term: " . $base . '/llms.txt'];
        $blocks = [implode("\n", $header)];
        $limit = $this->config->int('content.llms_full_limit', self::DEFAULT_LIMIT);
        $count = 0;
        $bytes = 0;
        foreach ($this->content->links() as $link) {
            if ($link->entry === null) {
                continue;
            }
            if ($count === $limit || $bytes >= self::MAX_BYTES) {
                $blocks[] = 'More content: ' . $base . '/llms.txt';

                break;
            }
            // EntryMarkdown starts with the one-line "# {title}" and a blank line.
            [$title, $rest] = explode("\n\n", rtrim($this->markdown->render($link->entry, $link->known), "\n"), 2) + [1 => ''];
            $block = $title . "\nSource: " . $link->url . "\n\n" . $rest;
            $blocks[] = $block;
            $bytes += strlen($block);
            $count++;
        }

        return implode("\n\n---\n\n", $blocks) . "\n";
    }
}
