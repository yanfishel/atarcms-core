<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Admin\ContentLocale;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Http\Exception\NotFound;
use AtarCms\I18n\Locales;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the collection and entry named in an admin content URL; anything that does not match is a 404.
 */
final class EntryRequest
{
    public function __construct(
        private readonly Collections $collections,
        private readonly EntryRepository $entries,
        private readonly Locales $locales,
        private readonly ContentLocale $contentLocale,
    ) {}

    public function collection(ServerRequestInterface $request): Collection
    {
        $name = $request->getAttribute('collection');
        $collection = is_string($name) ? $this->collections->find($name) : null;

        return $collection ?? throw new NotFound('No such collection');
    }

    /**
     * The entry of the URL's id; an entry of another collection is a 404, so a URL never reaches
     * an entry through another collection's permissions.
     */
    public function entry(ServerRequestInterface $request, Collection $collection): Entry
    {
        $id = $request->getAttribute('id');
        $entry = is_string($id) && strlen($id) <= 10 ? $this->entries->find((int) $id) : null;
        if ($entry === null || $entry->collection->name !== $collection->name) {
            throw new NotFound(sprintf('No %s entry #%s', $collection->name, is_string($id) ? $id : '?'));
        }

        return $entry;
    }

    /**
     * The content language of a screen that takes it from ?locale= (the list, "Add new", the order screen): for a
     * translatable collection the requested locale, remembered for the user, or the user's preference
     * (ContentLocale); the default locale otherwise.
     */
    public function contentLocale(ServerRequestInterface $request, Collection $collection): string
    {
        return $collection->translatable
            ? $this->contentLocale->resolve($request, $request->getQueryParams()['locale'] ?? null)
            : $this->locales->default();
    }

    /**
     * The edit page of $entry remembers its language when ?locale= names it (the form's language switch, the
     * redirect after creating a translation); see ContentLocale::rememberRequested().
     */
    public function rememberLocale(ServerRequestInterface $request, Entry $entry): void
    {
        if ($entry->collection->translatable) {
            $this->contentLocale->rememberRequested($request, $entry->locale);
        }
    }

    /**
     * A supported locale for a translatable collection (a submitted form's locale); the default locale otherwise.
     */
    public function locale(Collection $collection, mixed $value): string
    {
        return $collection->translatable && is_string($value) && $this->locales->isSupported($value)
            ? $value
            : $this->locales->default();
    }
}
