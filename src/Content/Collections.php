<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Content\Field\GalleryType;
use AtarCms\Content\Field\ImageType;
use AtarCms\Content\Field\RelationType;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use InvalidArgumentException;

/**
 * The configured collections (config/collections.php), validated as a whole.
 */
final class Collections
{
    /** @var array<string, Collection> */
    private readonly array $collections;

    public readonly Taxonomies $taxonomies;

    /**
     * @param array<mixed> $collections Collection objects, optionally keyed by their names
     * @param Taxonomies|null $taxonomies the taxonomies collections may use (none when null)
     */
    public function __construct(array $collections, ?Taxonomies $taxonomies = null)
    {
        $this->taxonomies = $taxonomies ?? new Taxonomies([]);
        $parsed = [];
        foreach ($collections as $key => $collection) {
            if (!$collection instanceof Collection) {
                throw new ConfigException(sprintf('config/collections.php must return Collection objects, got %s', get_debug_type($collection)));
            }
            if (is_string($key) && $key !== $collection->name) {
                throw new ConfigException(sprintf('Collection "%s" is listed under the key "%s"; use its name as the key', $collection->name, $key));
            }
            if (isset($parsed[$collection->name])) {
                throw new ConfigException(sprintf('Collection "%s" is defined twice', $collection->name));
            }
            $parsed[$collection->name] = $collection;
        }
        $presets = [];
        foreach ($parsed as $collection) {
            self::validate($collection, $parsed, $this->taxonomies);
            foreach (array_keys($collection->presets) as $slug) {
                if (isset($presets[$slug])) {
                    throw new ConfigException(sprintf('Collection "%s": the preset "%s" is declared by "%s" too; theme helpers find a preset by its slug alone', $collection->name, $slug, $presets[$slug]));
                }
                $presets[$slug] = $collection->name;
            }
        }
        self::validateSharedColumns($parsed);
        self::validateTaxonomyRoutes($parsed, $this->taxonomies);
        $this->collections = $parsed;
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->array('collections'), Taxonomies::fromConfig($config));
    }

    public function get(string $name): Collection
    {
        return $this->collections[$name] ?? throw new InvalidArgumentException(sprintf('Unknown collection "%s"', $name));
    }

    public function find(string $name): ?Collection
    {
        return $this->collections[$name] ?? null;
    }

    /**
     * @return array<string, Collection> in config order
     */
    public function all(): array
    {
        return $this->collections;
    }

    /**
     * @return array<string, Collection> the collections the site search finds entries of: those with a route() that
     *                                   are not ->searchable(false), in config order
     */
    public function searchable(): array
    {
        return array_filter(
            $this->collections,
            static fn(Collection $collection): bool => $collection->route !== null && $collection->searchable,
        );
    }

    /**
     * @return list<Collection> the collections whose entries carry terms of $taxonomy, in config order
     */
    public function usingTaxonomy(string $taxonomy): array
    {
        return array_values(array_filter(
            $this->collections,
            static fn(Collection $collection): bool => in_array($taxonomy, $collection->termTaxonomies(), true),
        ));
    }

    /**
     * The collections whose entries a term page of $taxonomy lists: those that use it and have a route.
     *
     * @return list<Collection>
     */
    public function listedBy(string $taxonomy): array
    {
        return array_values(array_filter(
            $this->usingTaxonomy($taxonomy),
            static fn(Collection $collection): bool => $collection->route !== null,
        ));
    }

    /**
     * Whether $taxonomy's terms are picked only through tags fields: some collection edits it with Field::tags() and
     * none lists it in taxonomies() (a checkbox list or select). Then the admin's terms list offers no "Add new": tags
     * are added in the entries' tags input.
     */
    public function onlyTagged(string $taxonomy): bool
    {
        $tagged = false;
        foreach ($this->collections as $collection) {
            if (in_array($taxonomy, $collection->taxonomies, true)) {
                return false;
            }
            $tagged = $tagged || $collection->tagsField($taxonomy) !== null;
        }

        return $tagged;
    }

    /**
     * Two routes with the same pattern cannot both be registered, and a taxonomy route must not hide entries. Only the
     * root route "/{slug}" may be shared by a collection and a taxonomy: one route then answers for both
     * (ContentModule::registerRoutes()).
     *
     * @param array<string, Collection> $all
     */
    private static function validateTaxonomyRoutes(array $all, Taxonomies $taxonomies): void
    {
        $seen = [];
        foreach ($taxonomies->all() as $taxonomy) {
            if ($taxonomy->route === null) {
                continue;
            }
            foreach ($all as $collection) {
                if ($collection->route === $taxonomy->route && !$taxonomy->isRoot()) {
                    throw new ConfigException(sprintf(
                        'Taxonomy "%s" and collection "%s" use the same route "%s"',
                        $taxonomy->name,
                        $collection->name,
                        $taxonomy->route,
                    ));
                }
                if ($taxonomy->isRoot() && $collection->hierarchical && $collection->route === Taxonomy::ROOT_ROUTE) {
                    throw new ConfigException(sprintf(
                        'Collection "%s" is hierarchical() at "/{slug}": its nested addresses would clash with those of the root taxonomy "%s"',
                        $collection->name,
                        $taxonomy->name,
                    ));
                }
            }
            if (isset($seen[$taxonomy->route])) {
                throw new ConfigException(sprintf('Taxonomies "%s" and "%s" use the same route "%s"', $seen[$taxonomy->route], $taxonomy->name, $taxonomy->route));
            }
            $seen[$taxonomy->route] = $taxonomy->name;
        }
    }

    /**
     * The generated column of an indexed field is computed for every entry, whatever its collection, so every
     * field with that name must produce values of the same column type; otherwise inserts fail in strict mode.
     *
     * @param array<string, Collection> $all
     */
    private static function validateSharedColumns(array $all): void
    {
        $indexed = [];
        foreach ($all as $collection) {
            foreach ($collection->fields as $field) {
                if ($field->indexed && !isset($indexed[$field->name])) {
                    $indexed[$field->name] = [(string) $field->type->columnType($field), $collection->name];
                }
            }
        }
        foreach ($all as $collection) {
            foreach ($collection->fields as $field) {
                if (!isset($indexed[$field->name])) {
                    continue;
                }
                [$type, $owner] = $indexed[$field->name];
                $own = $field->type->columnType($field);
                if ($own !== $type) {
                    throw new ConfigException(sprintf(
                        'The field "%s" is %s in collection "%s" but %s in "%s", where it is indexed(); fields with the same name as an indexed field must have the same type',
                        $field->name,
                        $own ?? $field->type->name() . ' (not indexable)',
                        $collection->name,
                        $type,
                        $owner,
                    ));
                }
            }
        }
    }

    /**
     * @param array<string, Collection> $all
     */
    private static function validate(Collection $collection, array $all, Taxonomies $taxonomies): void
    {
        $fail = static fn(string $message): ConfigException => new ConfigException(sprintf('Collection "%s": %s', $collection->name, $message));

        if ($collection->plain && !$collection->single) {
            throw $fail('a plain() collection must be single()');
        }
        if ($collection->single && $collection->hierarchical) {
            throw $fail('a single() collection cannot be hierarchical()');
        }
        if ($collection->single && ($collection->archive !== null || $collection->feed)) {
            throw $fail('a single() collection has no archive() or feed()');
        }
        if ($collection->route !== null && $collection->single === str_contains($collection->route, '{slug}')) {
            throw $fail($collection->single ? 'the route of a single() collection has no {slug}' : 'the route needs a {slug} placeholder');
        }
        if ($collection->route === null && ($collection->archive !== null || $collection->feed)) {
            throw $fail('archive() and feed() need a route()');
        }
        if ($collection->feed && $collection->archive === null) {
            throw $fail('feed() needs an archive(); the feed lives at {archive}/feed.xml');
        }

        if ($collection->presets !== []) {
            if ($collection->route === null || $collection->single || $collection->routeTaxonomy !== null || !str_ends_with($collection->route, '/{slug}')) {
                throw $fail('presets() need a route ending in {slug} ("/{slug}", "/info/{slug}") without a taxonomy placeholder');
            }
            if ($collection->hierarchical) {
                throw $fail('a hierarchical() collection cannot have presets(): a preset page stays at its address');
            }
            foreach ($collection->presets as $slug => $label) {
                if (!Slug::isValid($slug)) {
                    throw $fail(sprintf('the preset "%s" is not a valid address; use lowercase letters, digits and "-"', $slug));
                }
                if (trim($label) === '') {
                    throw $fail(sprintf('the preset "%s" needs a label (a translation key or plain text)', $slug));
                }
            }
        }

        $indexed = [];
        foreach ($collection->fields as $field) {
            if ($field->indexed) {
                if ($field->type->columnType($field) === null) {
                    throw $fail(sprintf('the %s field "%s" cannot be indexed', $field->type->name(), $field->name));
                }
                $indexed[] = $field->name;
            }
            if ($field->type instanceof RelationType && ($field->target === null || !isset($all[$field->target]))) {
                throw $fail(sprintf('the relation field "%s" points to the unknown collection "%s"', $field->name, $field->target ?? ''));
            }
        }
        foreach ($collection->shareImage ?? [] as $name) {
            $type = $collection->field($name)?->type;
            if (!$type instanceof ImageType && !$type instanceof GalleryType) {
                throw $fail(sprintf('shareImage() names "%s", which is not an image or gallery field of the collection', $name));
            }
        }
        if (!in_array($collection->sortColumn, Collection::SORTABLE, true) && !in_array($collection->sortColumn, $indexed, true)) {
            throw $fail(sprintf(
                'cannot sort by "%s"; use one of %s or an indexed field',
                $collection->sortColumn,
                implode(', ', Collection::SORTABLE),
            ));
        }

        $used = [];
        foreach ($collection->termTaxonomies() as $name) {
            if (isset($used[$name])) {
                throw $fail(sprintf('uses the taxonomy "%s" twice (in taxonomies() or tags fields)', $name));
            }
            $used[$name] = true;
            $taxonomy = $taxonomies->find($name) ?? throw $fail(sprintf('the taxonomy "%s" is not declared in config/taxonomies.php', $name));
            $tags = $collection->tagsField($name);
            if ($tags !== null && $taxonomy->hierarchical) {
                throw $fail(sprintf('the tags field "%s" needs a flat taxonomy; "%s" is hierarchical()', $tags->name, $name));
            }
        }

        $placeholder = $collection->routeTaxonomy;
        if ($placeholder !== null) {
            if ($collection->single || $collection->hierarchical) {
                throw $fail('a route with a taxonomy placeholder cannot be hierarchical() or single()');
            }
            if ($collection->route !== '/{' . $placeholder . '}/{slug}') {
                throw $fail(sprintf('the route must be exactly "/{%s}/{slug}" (the root route resolves it), not "%s"', $placeholder, $collection->route));
            }
            if (!in_array($placeholder, $collection->taxonomies, true)) {
                throw $fail(sprintf('the route placeholder {%s} must name one of its taxonomies()', $placeholder));
            }
            if ($taxonomies->get($placeholder)->route !== Taxonomy::ROOT_ROUTE) {
                throw $fail(sprintf('the taxonomy "%s" of the route placeholder must be routed at "/{slug}"', $placeholder));
            }
        }
    }
}
