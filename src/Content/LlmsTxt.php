<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Settings\SiteSettings;

/**
 * What /llms.txt holds (llmstxt.org): the site's name and description, its languages and a pointer to the entry
 * pages' markdown twins (EntryMarkdown), then, language by language (the default
 * first), one "## {label} ({locale})" section per routed collection and routed taxonomy with a markdown link per
 * public entry or term — the sitemap's URLs as a map an LLM reads in one request. A one-language site drops the
 * locale suffixes. After content.llms_limit links (default 1000) the listing stops and names the sitemap.
 */
final readonly class LlmsTxt
{
    public const int DEFAULT_LIMIT = 1000;

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

    public function render(): string
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
        $lines[] = '';
        // The entry pages' markdown twins (EntryMarkdown, ContentModule::registerRoutes()).
        $lines[] = "Append .md to an entry's URL for a markdown version.";

        $links = $this->links();
        $limit = $this->config->int('content.llms_limit', self::DEFAULT_LIMIT);
        $count = 0;
        foreach ($this->orderedLocales($default) as $locale) {
            foreach ($links as $section) {
                $heading = null;
                foreach ($section->links[$locale] ?? [] as [$title, $url]) {
                    if ($count === $limit) {
                        return implode("\n", [...$lines, '', 'More content: ' . $this->config->appUrl() . '/sitemap.xml']) . "\n";
                    }
                    if ($heading === null) {
                        $heading = $this->translator->label($locale, $section->label) . (count($languages) > 1 ? ' (' . $locale . ')' : '');
                        $lines[] = '';
                        $lines[] = '## ' . $heading;
                        $lines[] = '';
                    }
                    $lines[] = sprintf('- [%s](%s)', self::escape($title), $url);
                    $count++;
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * One section per routed collection, then per routed taxonomy, in config order: the label and, per locale, the
     * [title, absolute URL] of every public entry or term, through the sitemap's chunks (they carry the ancestors of
     * nested entries and leave out terms that only redirect).
     *
     * @return list<LlmsTxtSection>
     */
    private function links(): array
    {
        $base = $this->config->appUrl();
        $sections = [];
        foreach ($this->sitemap->pages() as $page) {
            $sections[$page->kind . ':' . $page->source] ??= new LlmsTxtSection($this->labelOf($page));
            $section = $sections[$page->kind . ':' . $page->source];
            if ($page->kind === SitemapPage::ENTRIES) {
                ['entries' => $entries, 'known' => $known] = $this->sitemap->entries($page);
                $paths = $this->urls->paths($entries, $known);
                foreach ($entries as $entry) {
                    if (isset($paths[$entry->id])) {
                        $section->links[$entry->locale][] = [$entry->title, $base . $paths[$entry->id]];
                    }
                }
            } else {
                $terms = $this->sitemap->terms($page);
                $paths = $this->termUrls->paths($terms);
                foreach ($terms as $term) {
                    if (isset($paths[$term->id])) {
                        $section->links[$term->locale][] = [$term->name, $base . $paths[$term->id]];
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

    /**
     * @return list<string> the site's locales, the default first
     */
    private function orderedLocales(string $default): array
    {
        return array_values(array_unique([$default, ...$this->locales->all()]));
    }

    /** A title inside a markdown link: backslashes and square brackets escaped. */
    private static function escape(string $title): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $title);
    }
}
