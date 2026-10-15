<?php

declare(strict_types=1);

namespace AtarCms\Content;

use Psr\Http\Message\ServerRequestInterface;

/**
 * An entry built in memory from an unsaved form (EntryService::preview()): nothing of it is stored. The admin's
 * preview attaches it to the request it renders the theme with, and the theme helpers that read an entry's terms or
 * URL from the database (terms(), termsFor(), entryUrl(), editUrl()) answer from it for $entry instead.
 */
final readonly class PreviewEntry
{
    /**
     * @param Entry $entry the stored entry's id (0 for a new entry) with the submitted values
     * @param array<string, list<Term>> $terms taxonomy => the chosen terms; a tag name that is not a term yet is a
     *                                         Term with id 0
     * @param array<int, Entry> $parents the chosen parent and its ancestors, by id (a hierarchical collection)
     * @param bool $valid false when a value failed validation and was left out (the preview is best effort)
     */
    public function __construct(
        public Entry $entry,
        public array $terms,
        public array $parents = [],
        public bool $valid = true,
    ) {}

    public static function of(ServerRequestInterface $request): ?self
    {
        $preview = $request->getAttribute(self::class);

        return $preview instanceof self ? $preview : null;
    }

    public function attach(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withAttribute(self::class, $this);
    }

    /**
     * Whether $entry is this preview's entry (the very object handed to the templates).
     */
    public function isFor(Entry $entry): bool
    {
        return $entry === $this->entry;
    }

    /**
     * @return list<Term>
     */
    public function terms(string $taxonomy): array
    {
        return $this->terms[$taxonomy] ?? [];
    }

    /**
     * The chosen term of the collection's route placeholder (the article's section), or null.
     */
    public function routeTerm(): ?Term
    {
        $taxonomy = $this->entry->collection->routeTaxonomy;

        return $taxonomy === null ? null : ($this->terms($taxonomy)[0] ?? null);
    }
}
