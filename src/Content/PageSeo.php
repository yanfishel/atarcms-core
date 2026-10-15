<?php

declare(strict_types=1);

namespace AtarCms\Content;

/**
 * The title and description of a public page for search engines and link previews (the theme layout's <title>, meta
 * description and socialMeta()), resolved along the sections: an entry's own texts win; else an article inherits
 * from its section, then that section's parents up to the top level; a section page from its own texts, then its
 * parents. Each text resolves on its own. A section's title may be a template: PLACEHOLDER ("{title}") stands for
 * the title of the page that uses it (the article's title, the section's name); only such a template is inherited
 * downwards, a title without it is used on the section's own page only. Descriptions are inherited as they are. A
 * description that is still null falls back to the site's (Settings → Site settings) in the theme layout and
 * SocialMeta; the site title never takes part (it is the home page's title). Values are plain user text: themes
 * escape them ($this->e()).
 *
 * ContentController and TermController pass it to their templates as $seo, built from terms they have already
 * loaded (no extra query).
 */
final readonly class PageSeo
{
    public const string PLACEHOLDER = '{title}';

    /**
     * @param string $title never empty
     * @param string|null $description null: none of the page's own or inherited (the site's applies)
     */
    public function __construct(
        public string $title,
        public ?string $description = null,
    ) {}

    /**
     * An entry's page. $sections is the trail of its section (the term of its route's placeholder, TermTree::trail():
     * the top-level section first, its own section last), or [] for an entry outside sections (pages, singles).
     *
     * @param list<Term> $sections
     */
    public static function forEntry(Entry $entry, array $sections): self
    {
        $template = self::text(self::inheritedTitle($sections)?->seo->metaTitle);

        return new self(
            self::text($entry->seo->metaTitle) ?? ($template === null ? $entry->title : self::fill($template, $entry->title)),
            self::text($entry->seo->metaDescription) ?? self::text(self::inheritedDescription($sections)?->seo->metaDescription),
        );
    }

    /**
     * A term's page (a section). $ancestors are its parents, the top-level one first (TermTree::trail() without the
     * term itself), or [] when they are not loaded (a taxonomy without texts).
     *
     * @param list<Term> $ancestors
     */
    public static function forTerm(Term $term, array $ancestors): self
    {
        $title = self::text($term->seo->metaTitle) ?? self::text(self::inheritedTitle($ancestors)?->seo->metaTitle);

        return new self(
            $title === null ? $term->name : self::fill($title, $term->name),
            self::text($term->seo->metaDescription) ?? self::text(self::inheritedDescription($ancestors)?->seo->metaDescription),
        );
    }

    /**
     * The term whose title the pages below $trail inherit: the nearest one (from the end) whose title holds
     * PLACEHOLDER; titles without it are skipped.
     *
     * @param list<Term> $trail the top-level term first
     */
    public static function inheritedTitle(array $trail): ?Term
    {
        foreach (array_reverse($trail) as $term) {
            if (str_contains(self::text($term->seo->metaTitle) ?? '', self::PLACEHOLDER)) {
                return $term;
            }
        }

        return null;
    }

    /**
     * The term whose description the pages below $trail inherit: the nearest one (from the end) with a description.
     *
     * @param list<Term> $trail the top-level term first
     */
    public static function inheritedDescription(array $trail): ?Term
    {
        foreach (array_reverse($trail) as $term) {
            if (self::text($term->seo->metaDescription) !== null) {
                return $term;
            }
        }

        return null;
    }

    /**
     * $template with every PLACEHOLDER replaced by $title, in one pass: a title that itself holds "{title}" stays as it
     * is. A template without the placeholder comes back unchanged.
     */
    public static function fill(string $template, string $title): string
    {
        return str_replace(self::PLACEHOLDER, $title, $template);
    }

    /**
     * A stored text, null when empty (stored texts are trimmed and never '', but a theme may build its own).
     */
    private static function text(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }
}
