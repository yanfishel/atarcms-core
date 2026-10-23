<?php

declare(strict_types=1);

namespace AtarCms\Content\Llms;

use AtarCms\Content\Collections;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\Sitemap;
use AtarCms\Content\SitemapPage;
use AtarCms\Content\TermUrls;
use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Settings\SiteSettings;
use Generator;

/**
 * What /llms.txt (LlmsTxt), /llms-full.txt (LlmsFullTxt) and /sitemap.md (MarkdownSitemap) share: the header — "# {site name}", the default
 * language's meta description as "> {description}", "Site languages: en (default), ru" — and the public content in
 * their order: language by language (the default first), one section per routed collection, then per routed
 * taxonomy, in config order, through the sitemap's chunks (they carry the ancestors of nested entries and leave out
 * terms that only redirect). A one-language site drops the locale suffixes of the section headings.
 */
final readonly class LlmsContent
{
    public function __construct(
        private Sitemap $sitemap,
        private Collections $collections,
        private EntryUrls $urls,
        private TermUrls $termUrls,
        private Locales $locales,
        private Translator $translator,
        private SiteSettings $settings,
        private Config $config,
    ) {}

    /**
     * @param string $suffix after the site name in the heading (" — sitemap")
     *
     * @return list<string> the header's lines
     */
    public function header(string $suffix = ''): array
    {
        $default = $this->locales->default();
        $site = $this->settings->of($default);
        $lines = ['# ' . $this->settings->siteName($default, $site) . $suffix];
        if ($site->metaDescription !== null && $site->metaDescription !== '') {
            $lines[] = '';
            $lines[] = '> ' . $site->metaDescription;
        }
        $languages = array_map(fn(string $code): string => $code === $default ? $code . ' (default)' : $code, $this->locales->all());
        $lines[] = '';
        $lines[] = 'Site languages: ' . implode(', ', $languages);

        return $lines;
    }

    /**
     * links() as markdown lines, each section "", "## {heading}", "" and a "- [title](URL)" line per link; after
     * $limit links the listing stops with "", "More content: {/sitemap.xml}" (/llms.txt, /sitemap.md).
     *
     * @return list<string>
     */
    public function listing(int $limit): array
    {
        $lines = [];
        $count = 0;
        $current = null;
        foreach ($this->links() as $heading => $link) {
            if ($count === $limit) {
                return [...$lines, '', 'More content: ' . $this->config->appUrl() . '/sitemap.xml'];
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

        return $lines;
    }

    /**
     * Every public entry and term in order, keyed by its section's heading ("Articles (en)"; keys repeat).
     *
     * @return Generator<string, LlmsLink>
     */
    public function links(): Generator
    {
        $multilingual = count($this->locales->all()) > 1;
        $sections = $this->sections();
        foreach (array_values(array_unique([$this->locales->default(), ...$this->locales->all()])) as $locale) {
            foreach ($sections as $section) {
                $heading = $this->translator->label($locale, $section->label) . ($multilingual ? ' (' . $locale . ')' : '');
                foreach ($section->links[$locale] ?? [] as $link) {
                    yield $heading => $link;
                }
            }
        }
    }

    /**
     * @return list<LlmsSection>
     */
    private function sections(): array
    {
        $base = $this->config->appUrl();
        $sections = [];
        foreach ($this->sitemap->pages() as $page) {
            $sections[$page->kind . ':' . $page->source] ??= new LlmsSection($this->labelOf($page));
            $section = $sections[$page->kind . ':' . $page->source];
            if ($page->kind === SitemapPage::ENTRIES) {
                ['entries' => $entries, 'known' => $known] = $this->sitemap->entries($page);
                $paths = $this->urls->paths($entries, $known);
                foreach ($entries as $entry) {
                    if (isset($paths[$entry->id])) {
                        $section->links[$entry->locale][] = new LlmsLink($entry->title, $base . $paths[$entry->id], $entry, $known);
                    }
                }
            } else {
                $terms = $this->sitemap->terms($page);
                $paths = $this->termUrls->paths($terms);
                foreach ($terms as $term) {
                    if (isset($paths[$term->id])) {
                        $section->links[$term->locale][] = new LlmsLink($term->name, $base . $paths[$term->id]);
                    }
                }
            }
        }

        return array_values($sections);
    }

    /** A title inside a markdown link: backslashes and square brackets escaped. */
    private static function escape(string $title): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $title);
    }

    private function labelOf(SitemapPage $page): string
    {
        return $page->kind === SitemapPage::ENTRIES
            ? $this->collections->get($page->source)->label
            : $this->collections->taxonomies->get($page->source)->label;
    }
}
