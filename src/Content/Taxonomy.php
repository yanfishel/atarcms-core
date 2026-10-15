<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Admin\AdminIcon;
use AtarCms\Kernel\ConfigException;

/**
 * A vocabulary of terms, declared in config/taxonomies.php:
 * Taxonomy::make('categories')->label('Categories')->hierarchical()->route('/category/{slug}').
 * Collections use it with ->taxonomies(['categories']) or a Field::tags() field (flat taxonomies only).
 */
final class Taxonomy
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** The route of term pages at the top level of the site (sections). */
    public const string ROOT_ROUTE = '/{slug}';

    /** Managing terms (the admin "Taxonomies" screens) needs this permission. */
    public const string PERMISSION = 'taxonomies.manage';

    /** A translation key or plain text. */
    public private(set) string $label;

    public private(set) bool $hierarchical = false;

    public private(set) ?string $route = null;

    public private(set) int $perPage = 10;

    /** A term with children redirects to its first leaf; see redirectToFirstChild(). */
    public private(set) bool $redirectToFirstChild = false;

    /** The icon of the admin menu item (a symbol of the admin icon sprite); null for the default. */
    public private(set) ?string $icon = null;

    private function __construct(public readonly string $name)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new ConfigException(sprintf(
                'Invalid taxonomy name "%s"; use lowercase letters, digits and "_", starting with a letter (at most 32 characters)',
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
     * Terms get a parent and a position (e.g. categories); flat taxonomies suit tags.
     */
    public function hierarchical(): self
    {
        $this->hierarchical = true;

        return $this;
    }

    /**
     * A public page per term at $pattern ("/category/{slug}"), listing $perPage entries per page (?page=N). The first
     * segment must be literal, except in the root route "/{slug}" (ROOT_ROUTE: term pages at the top level, such as
     * sections), which a collection routed at "/{slug}" shares: its entries answer first, then the terms
     * (ContentModule), and ReservedSlugs keeps their slugs apart.
     */
    public function route(string $pattern, int $perPage = 10): self
    {
        if (!str_starts_with($pattern, '/') || str_contains($pattern, '//') || preg_match('~[\s?#\\\\]~', $pattern) === 1) {
            throw new ConfigException(sprintf(
                'The route "%s" of taxonomy "%s" must start with "/" and contain no "//", spaces, "?", "#" or "\\"',
                $pattern,
                $this->name,
            ));
        }
        $rest = str_replace('{slug}', '', $pattern, $count);
        if ($count !== 1 || str_contains($rest, '{') || str_contains($rest, '}')) {
            throw new ConfigException(sprintf('The route "%s" of taxonomy "%s" needs {slug} once and no other placeholder', $pattern, $this->name));
        }
        if ($pattern !== self::ROOT_ROUTE && preg_match('~^/[^/{}]+/~', $pattern) !== 1) {
            throw new ConfigException(sprintf(
                'The route "%s" of taxonomy "%s" needs a literal first segment ("/category/{slug}") or must be "/{slug}"',
                $pattern,
                $this->name,
            ));
        }
        if (str_starts_with($pattern, '/admin/')) {
            throw new ConfigException(sprintf('The route "%s" of taxonomy "%s" is under /admin, which is reserved', $pattern, $this->name));
        }
        if ($perPage < 1 || $perPage > 100) {
            throw new ConfigException(sprintf('Taxonomy "%s": entries per page must be between 1 and 100', $this->name));
        }
        $this->route = $pattern;
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * The icon of the taxonomy's admin menu item, a symbol of resources/admin/assets/icons.svg ("map-pin").
     * Without it: "folder-tree" for a hierarchical() taxonomy, "tag" otherwise.
     */
    public function icon(string $icon): self
    {
        if (preg_match(AdminIcon::NAME_PATTERN, $icon) !== 1) {
            throw new ConfigException(sprintf('Invalid icon "%s" for taxonomy "%s"; use lowercase letters, digits and "-"', $icon, $this->name));
        }
        $this->icon = $icon;

        return $this;
    }

    /**
     * The router pattern, with {slug} constrained to slugs; null without a route.
     */
    public function routePattern(): ?string
    {
        return $this->route === null ? null : str_replace('{slug}', '{slug:' . Slug::PATTERN . '}', $this->route);
    }

    /**
     * A term with children has no page of its own: its URL redirects (302, the tree can be reordered) to its first
     * child by tree order, down to a leaf. Needs a hierarchical() taxonomy with a route() (checked by Taxonomies).
     */
    public function redirectToFirstChild(): self
    {
        $this->redirectToFirstChild = true;

        return $this;
    }

    /**
     * Whether the term pages live at the top level of the site ("/{slug}").
     */
    public function isRoot(): bool
    {
        return $this->route === self::ROOT_ROUTE;
    }

    /**
     * A hierarchical taxonomy at the top level of the site (sections): a menu can show its whole term tree.
     */
    public function isRootTree(): bool
    {
        return $this->hierarchical && $this->isRoot();
    }
}
