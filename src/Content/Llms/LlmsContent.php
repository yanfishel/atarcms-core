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
 * What /llms.txt (LlmsTxt) and /llms-full.txt (LlmsFullTxt) share: the header — "# {site name}", the default
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
     * @return list<string> the header's lines
     */
    public function header(): array
    {
        $default = $this->locales->default();
        $site = $this->settings->of($default);
        $lines = ['# ' . $this->settings->siteName($default, $site)];
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

    private function labelOf(SitemapPage $page): string
    {
        return $page->kind === SitemapPage::ENTRIES
            ? $this->collections->get($page->source)->label
            : $this->collections->taxonomies->get($page->source)->label;
    }
}
