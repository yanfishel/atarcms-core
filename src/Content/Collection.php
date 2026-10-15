<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Admin\AdminIcon;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\GalleryType;
use AtarCms\Content\Field\ImageType;
use AtarCms\Content\Field\TagsType;
use AtarCms\Kernel\ConfigException;

/**
 * A content type, declared in config/collections.php:
 * Collection::make('news')->route('/news/{slug}')->archive('/news')->fields([...]).
 * Collections validates the combination of settings.
 */
final class Collection
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** Entry columns a collection can sort by; indexed fields are sortable too. */
    public const array SORTABLE = ['published_at', 'title', 'slug', 'position', 'created_at', 'updated_at'];

    /** A translation key or plain text. */
    public private(set) string $label;

    public private(set) ?string $route = null;

    /** The taxonomy named by the route's first segment ("sections" in "/{sections}/{slug}"); null for none. */
    public private(set) ?string $routeTaxonomy = null;

    public private(set) ?string $archive = null;

    public private(set) int $perPage = 10;

    public private(set) string $sortColumn = 'published_at';

    public private(set) string $sortDirection = 'desc';

    /** @var array<string, Field> */
    public private(set) array $fields = [];

    /** @var list<string> taxonomies whose terms editors pick from a list (tags fields add their own) */
    public private(set) array $taxonomies = [];

    public private(set) bool $hierarchical = false;

    public private(set) bool $single = false;

    /** A single() page edited as its fields only; see plain(). */
    public private(set) bool $plain = false;

    public private(set) bool $translatable = false;

    public private(set) bool $feed = false;

    /** The site search finds the entries (only of collections with a route); see searchable(). */
    public private(set) bool $searchable = true;

    /** The icon of the admin menu item (a symbol of the admin icon sprite); null for the default. */
    public private(set) ?string $icon = null;

    /** @var list<string>|null the fields whose image is the entry's main photo (see shareImage()); null for the default */
    public private(set) ?array $shareImage = null;

    /** @var array<string, string> the preset pages: slug (the preset's key) => label (a translation key or plain text); see presets() */
    public private(set) array $presets = [];

    private function __construct(public readonly string $name)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new ConfigException(sprintf(
                'Invalid collection name "%s"; use lowercase letters, digits and "_", starting with a letter (at most 32 characters)',
                $name,
            ));
        }
        $this->label = $name;
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The public URL of an entry: "/news/{slug}". A single() collection has no placeholder ("/contact"). The first
     * segment may name one of the collection's taxonomies() routed at "/{slug}" ("/{sections}/{slug}", checked by
     * Collections): the URL then starts with the slug of the entry's term of that taxonomy, which becomes required
     * and single-valued (EntryTerms), and the entry's URL follows its term.
     */
    public function route(string $pattern): self
    {
        $this->assertPath($pattern, 'route');
        $taxonomy = null;
        $tail = $pattern;
        if (preg_match('~^/\{([a-z][a-z0-9_]{0,31})\}(/.*)$~', $pattern, $match) === 1 && $match[1] !== 'slug') {
            $taxonomy = $match[1];
            $tail = $match[2];
        }
        $rest = str_replace('{slug}', '', $tail, $count);
        if ($count > 1 || str_contains($rest, '{') || str_contains($rest, '}')) {
            throw new ConfigException(sprintf(
                'The route "%s" of collection "%s" may only use {slug}, once, and a taxonomy placeholder as its first segment ("/{sections}/{slug}")',
                $pattern,
                $this->name,
            ));
        }
        $this->route = $pattern;
        $this->routeTaxonomy = $taxonomy;

        return $this;
    }

    /**
     * A paginated list of the collection's entries at $path (e.g. "/news"); ?page=N selects a page.
     */
    public function archive(string $path, int $perPage = 10): self
    {
        $this->assertPath($path, 'archive');
        if (str_contains($path, '{') || str_contains($path, '}')) {
            throw new ConfigException(sprintf('The archive path "%s" of collection "%s" cannot have placeholders', $path, $this->name));
        }
        if ($perPage < 1 || $perPage > 100) {
            throw new ConfigException(sprintf('Collection "%s": entries per page must be between 1 and 100', $this->name));
        }
        $this->archive = $path;
        $this->perPage = $perPage;

        return $this;
    }

    public function sortBy(string $column, string $direction = 'desc'): self
    {
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new ConfigException(sprintf('Collection "%s": the sort direction must be "asc" or "desc"', $this->name));
        }
        $this->sortColumn = $column;
        $this->sortDirection = $direction;

        return $this;
    }

    /**
     * @param array<mixed> $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = [];
        foreach ($fields as $field) {
            if (!$field instanceof Field) {
                throw new ConfigException(sprintf('Collection "%s": fields() takes Field objects, got %s', $this->name, get_debug_type($field)));
            }
            if (isset($this->fields[$field->name])) {
                throw new ConfigException(sprintf('Collection "%s" declares the field "%s" twice', $this->name, $field->name));
            }
            $this->fields[$field->name] = $field;
        }

        return $this;
    }

    /**
     * Entries carry terms of these taxonomies (config/taxonomies.php), picked from a list in the entry form.
     *
     * @param array<mixed> $names
     */
    public function taxonomies(array $names): self
    {
        $list = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new ConfigException(sprintf('Collection "%s": taxonomies() takes taxonomy names, got %s', $this->name, get_debug_type($name)));
            }
            if (in_array($name, $list, true)) {
                throw new ConfigException(sprintf('Collection "%s" lists the taxonomy "%s" twice', $this->name, $name));
            }
            $list[] = $name;
        }
        $this->taxonomies = $list;

        return $this;
    }

    /**
     * @return list<string> every taxonomy whose terms the entries carry: taxonomies() first, then those of tags fields
     */
    public function termTaxonomies(): array
    {
        $names = $this->taxonomies;
        foreach ($this->fields as $field) {
            if ($field->type instanceof TagsType && $field->taxonomy !== null) {
                $names[] = $field->taxonomy;
            }
        }

        return $names;
    }

    /**
     * The tags field that edits the terms of $taxonomy, if any.
     */
    public function tagsField(string $taxonomy): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->type instanceof TagsType && $field->taxonomy === $taxonomy) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Entries get a parent and a position; the route matches nested slug paths ("/about/team").
     */
    public function hierarchical(): self
    {
        $this->hierarchical = true;

        return $this;
    }

    /**
     * Exactly one entry per locale, e.g. site settings or a home page.
     */
    public function single(): self
    {
        $this->single = true;

        return $this;
    }

    /**
     * A single() page that is always there, such as a home or contacts page: the entry form shows only the fields (no
     * title, status, publication date or SEO inputs), the title is the collection's label in the entry's language and
     * every save publishes it (EntryService). Search engines and link previews use the site settings. Needs single()
     * (checked by Collections).
     */
    public function plain(): self
    {
        $this->plain = true;

        return $this;
    }

    /**
     * Entries exist in every configured locale; otherwise only in the default locale.
     */
    public function translatable(): self
    {
        $this->translatable = true;

        return $this;
    }

    /**
     * An RSS feed at {archive}/feed.xml.
     */
    public function feed(): self
    {
        $this->feed = true;

        return $this;
    }

    /**
     * Whether the site search (/search) finds the entries; on by default for every collection with a route().
     * After turning it off, run "bin/atar search:reindex" (or save the entries) to drop their index rows.
     */
    public function searchable(bool $searchable = true): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * The icon of the collection's admin menu item, a symbol of resources/admin/assets/icons.svg ("calendar").
     * Without it: "home" for a single(), "newspaper" with an archive(), "file-text" otherwise.
     */
    public function icon(string $icon): self
    {
        if (preg_match(AdminIcon::NAME_PATTERN, $icon) !== 1) {
            throw new ConfigException(sprintf('Invalid icon "%s" for collection "%s"; use lowercase letters, digits and "-"', $icon, $this->name));
        }
        $this->icon = $icon;

        return $this;
    }

    /**
     * The image or gallery fields whose image is an entry's main photo, in order: the first one holding an image wins
     * (a gallery gives its first photo). The main photo is the background of the generated share image and the
     * og:image of a page without one. Without this call: the first image field and the first gallery field, in field
     * order; no fields (shareImage()) turns both off for the collection. Collections checks the names.
     */
    public function shareImage(string ...$fields): self
    {
        $this->shareImage = array_values($fields);

        return $this;
    }

    /**
     * Pages the collection always has, such as a privacy policy: slug => label (a translation key or plain text).
     * "bin/atar migrate" creates each missing one in every language of the collection (PresetPages), titled by its
     * label in that language, published, with the same address everywhere ("/privacy", "/ru/privacy"). A preset page
     * is an ordinary entry with three locks (EntryService): it is never deleted, its address never changes and it is
     * always published. Needs a route ending in "/{slug}" that is not hierarchical() (checked by Collections; the
     * slugs against the other routes at boot).
     *
     * @param array<mixed> $presets
     */
    public function presets(array $presets): self
    {
        $list = [];
        foreach ($presets as $slug => $label) {
            if (!is_string($slug) || !is_string($label)) {
                throw new ConfigException(sprintf('Collection "%s": presets() takes slug => label strings', $this->name));
            }
            $list[$slug] = $label;
        }
        $this->presets = $list;

        return $this;
    }

    /**
     * @return list<string> the fields of the main photo (shareImage(), or the default), in order
     */
    public function shareImageFields(): array
    {
        if ($this->shareImage !== null) {
            return $this->shareImage;
        }
        $first = [];
        foreach ($this->fields as $name => $field) {
            $kind = match (true) {
                $field->type instanceof ImageType => 'image',
                $field->type instanceof GalleryType => 'gallery',
                default => null,
            };
            if ($kind !== null && !isset($first[$kind])) {
                $first[$kind] = $name;
            }
        }

        return array_values($first);
    }

    /**
     * The icon of the collection in the admin: its icon(), or the default for its kind.
     */
    public function adminIcon(): string
    {
        return $this->icon ?? ($this->single ? 'home' : ($this->archive !== null ? 'newspaper' : 'file-text'));
    }

    /**
     * Its entries are articles for search engines (a schema.org Article, see StructuredData): dated or sectioned
     * content, a collection with an archive or a route taxonomy. Pages without either (standalone or nested) and
     * singles are not.
     */
    public function articleLike(): bool
    {
        return !$this->single && ($this->archive !== null || $this->routeTaxonomy !== null);
    }

    public function field(string $name): ?Field
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * The permission for an action on this collection's entries, e.g. "entries.news.edit".
     */
    public function permission(string $action): string
    {
        return 'entries.' . $this->name . '.' . $action;
    }

    /**
     * The router pattern: {slug} constrained to slugs, or to nested slug paths in a hierarchical collection; a
     * taxonomy placeholder constrained to nested slug paths (the trail of the entry's section, "about/team").
     */
    public function routePattern(): ?string
    {
        if ($this->route === null) {
            return null;
        }
        $slug = $this->hierarchical ? Slug::PATH_PATTERN : Slug::PATTERN;
        $pattern = str_replace('{slug}', '{slug:' . $slug . '}', $this->route);

        return $this->routeTaxonomy === null
            ? $pattern
            : str_replace('{' . $this->routeTaxonomy . '}', '{' . $this->routeTaxonomy . ':' . Slug::PATH_PATTERN . '}', $pattern);
    }

    public function feedPath(): ?string
    {
        return $this->archive === null ? null : rtrim($this->archive, '/') . '/feed.xml';
    }

    private function assertPath(string $path, string $what): void
    {
        if (!str_starts_with($path, '/') || str_contains($path, '//') || preg_match('~[\s?#\\\\]~', $path) === 1) {
            throw new ConfigException(sprintf(
                'The %s "%s" of collection "%s" must start with "/" and contain no "//", spaces, "?", "#" or "\\"',
                $what,
                $path,
                $this->name,
            ));
        }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            throw new ConfigException(sprintf('The %s "%s" of collection "%s" is under /admin, which is reserved', $what, $path, $this->name));
        }
    }
}
