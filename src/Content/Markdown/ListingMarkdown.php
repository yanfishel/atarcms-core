<?php

declare(strict_types=1);

namespace AtarCms\Content\Markdown;

use AtarCms\Content\Collection;
use AtarCms\Content\EntryPage;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\PageSeo;
use AtarCms\Content\StructuredData\StructuredData;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermUrls;
use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\SiteTime;
use Closure;
use DateTimeImmutable;

/**
 * The markdown twins of the listing pages, for AI agents: a collection's archive ("/news.md", archive()) and a term
 * page of a routed taxonomy ("/tag/releases.md", "/about/team.md" for a section, term()), one page of the HTML
 * listing each ("?page=N" lists the same entries). The document:
 * - YAML frontmatter (Frontmatter): title (the term's name; the collection's label in the page's language),
 *   description (a term's PageSeo description — its own, else a parent section's —, else the site's of the language,
 *   like an entry twin's; left out when empty), url (the canonical page, "?page=N" included), lang, and last_updated
 *   (the latest change of what the page lists: the newest modification time — the later of the last save and the
 *   publication — of its entries, and the term's own last save; left out on an empty archive page). A listing has no
 *   publication time;
 * - "# {title}", then "> {meta description}" when the term has its own (archives have none);
 * - facts: "Language: {locale}" with links to the twins of the listing in the other languages, and on a paginated
 *   listing "Page N of M" with links to the previous and next pages' twins;
 * - "## Entries": "- [title](page URL) — {publication date}" per listed entry (no date for a plain() collection's);
 *   left out when the page lists none;
 * - "## {taxonomy label}" with the term's children, for a term of a hierarchical taxonomy that has them.
 * Links to other pages (entries, child terms) go to their canonical page URLs, as in /llms.txt and an entry twin's
 * relation and term lines (the pages negotiate markdown, their twins are one ".md" away); links to this listing in
 * another page or language go to its twins, like an entry twin's translation links. Labels are translated into the
 * page's language; the structure words are English, like EntryMarkdown's.
 */
final readonly class ListingMarkdown
{
    public function __construct(
        private EntryUrls $entryUrls,
        private TermUrls $termUrls,
        private TermRepository $terms,
        private Locales $locales,
        private Translator $translator,
        private SiteTime $siteTime,
        private Config $config,
    ) {}

    /**
     * A page of a collection's archive.
     *
     * @param string $url the page's absolute canonical URL
     * @param string $siteDescription the site's description in $locale (MarkdownPages::siteDescription())
     */
    public function archive(Collection $collection, string $locale, EntryPage $page, string $url, string $siteDescription): string
    {
        $base = $this->config->appUrl();
        $translations = [];
        if ($collection->translatable) {
            foreach ($this->locales->all() as $other) {
                $path = $other === $locale ? null : $this->entryUrls->archivePath($collection, $other, 1, true);
                if ($path !== null) {
                    $translations[] = RichTextMarkdown::link($other, $base . $path);
                }
            }
        }

        return $this->document(
            $this->translator->label($locale, $collection->label),
            $siteDescription,
            null,
            $url,
            $locale,
            $translations,
            $page,
            fn(int $number): string => $base . $this->entryUrls->archivePath($collection, $locale, $number, true),
            null,
            [],
        );
    }

    /**
     * A page of a term page.
     *
     * @param PageSeo $seo the page's SEO texts (PageSeo::forTerm(), its description inherited along the sections)
     * @param array<int, Term> $tree the terms of the term's taxonomy and locale by id when loaded (for its trail and its
     *                               children); [] for a taxonomy that is not hierarchical
     * @param string $url the page's absolute canonical URL
     * @param string $siteDescription the site's description in the term's language (MarkdownPages::siteDescription())
     */
    public function term(Taxonomy $taxonomy, Term $term, EntryPage $page, PageSeo $seo, array $tree, string $url, string $siteDescription): string
    {
        $base = $this->config->appUrl();
        $others = array_values(array_filter(
            $this->terms->translations($term->translationGroup),
            fn(Term $translation): bool => $translation->id !== $term->id && in_array($translation->locale, $this->locales->all(), true),
        ));
        $translations = [];
        foreach ($this->termUrls->paths($others, true) as $id => $path) {
            foreach ($others as $other) {
                if ($other->id === $id) {
                    $translations[] = RichTextMarkdown::link($other->locale, $base . $path);
                }
            }
        }
        $children = array_values(array_filter($tree, static fn(Term $child): bool => $child->parentId === $term->id));
        usort($children, static fn(Term $a, Term $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);
        $sections = [];
        if ($children !== []) {
            $items = array_map(
                fn(Term $child): string => '- ' . RichTextMarkdown::link(RichTextMarkdown::line($child->name), $base . $this->termUrls->path($child, 1, $tree)),
                $children,
            );
            $sections[] = RichTextMarkdown::heading(2, RichTextMarkdown::line($this->translator->label($term->locale, $taxonomy->label))) . "\n\n" . implode("\n", $items);
        }

        return $this->document(
            $term->name,
            $seo->description ?? $siteDescription,
            $term->seo->metaDescription,
            $url,
            $term->locale,
            $translations,
            $page,
            fn(int $number): string => $base . $this->termUrls->path($term, $number, $tree, true),
            $term->updatedAt,
            $sections,
        );
    }

    /**
     * @param string $description the frontmatter's description, '' for none
     * @param list<string> $translations links to the listing's twins in the other languages
     * @param Closure(int): string $twinUrl the absolute URL of page N's twin
     * @param list<string> $sections the "## …" sections after the entries
     */
    private function document(
        string $title,
        string $description,
        ?string $own,
        string $url,
        string $locale,
        array $translations,
        EntryPage $page,
        Closure $twinUrl,
        ?DateTimeImmutable $modified,
        array $sections,
    ): string {
        $fields = ['title' => $title];
        $description = trim($description);
        if ($description !== '') {
            $fields['description'] = $description;
        }
        $fields['url'] = $url;
        $fields['lang'] = $locale;
        foreach ($page->entries as $entry) {
            $changed = $entry->publishedAt === null ? $entry->updatedAt : max($entry->updatedAt, $entry->publishedAt);
            $modified = $modified === null ? $changed : max($modified, $changed);
        }
        if ($modified !== null) {
            $fields['last_updated'] = StructuredData::time($modified);
        }

        $parts = [RichTextMarkdown::heading(1, RichTextMarkdown::line($title))];
        $own = trim($own ?? '');
        if ($own !== '') {
            // Escaped as a line start: "> 1. …" would be a list inside the quote.
            $parts[] = '> ' . RichTextMarkdown::escapeLineStarts(RichTextMarkdown::line($own));
        }
        $facts = ['- Language: ' . $locale . ($translations === [] ? '' : ' (translations: ' . implode(', ', $translations) . ')')];
        if ($page->lastPage() > 1) {
            $pages = [];
            if ($page->hasPrevious()) {
                $pages[] = RichTextMarkdown::link('previous', $twinUrl($page->number - 1));
            }
            if ($page->hasNext()) {
                $pages[] = RichTextMarkdown::link('next', $twinUrl($page->number + 1));
            }
            $facts[] = sprintf('- Page %d of %d: %s', $page->number, $page->lastPage(), implode(', ', $pages));
        }
        $parts[] = implode("\n", $facts);

        if ($page->entries !== []) {
            $base = $this->config->appUrl();
            $paths = $this->entryUrls->paths($page->entries);
            $items = [];
            foreach ($page->entries as $entry) {
                $name = RichTextMarkdown::line($entry->title);
                // Unlinked, the title starts the item's text: "1. Intro" would open a nested list.
                $item = '- ' . (isset($paths[$entry->id]) ? RichTextMarkdown::link($name, $base . $paths[$entry->id]) : RichTextMarkdown::escapeLineStarts($name));
                if (!$entry->collection->plain && $entry->publishedAt !== null) {
                    $item .= ' — ' . $this->siteTime->readable($entry->publishedAt, $locale, false);
                }
                $items[] = $item;
            }
            $parts[] = "## Entries\n\n" . implode("\n", $items);
        }

        return Frontmatter::render($fields) . "\n" . implode("\n\n", [...$parts, ...$sections]) . "\n";
    }
}
