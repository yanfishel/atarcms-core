<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\Collection;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\PublicContent;
use AtarCms\Content\SlugHistoryRepository;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermUrls;
use AtarCms\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The 301 from a previous slug (SlugHistoryRepository) to the current address, asked by the public controllers only
 * when nothing answers to the slug: a current entry or term always wins over the history. RootController, which tries
 * several owners in turn, defers the history until none of them answers.
 */
final class SlugRedirects
{
    public function __construct(
        private readonly SlugHistoryRepository $history,
        private readonly EntryRepository $entries,
        private readonly TermRepository $terms,
        private readonly PublicContent $content,
        private readonly EntryUrls $entryUrls,
        private readonly TermUrls $termUrls,
        private readonly Responses $responses,
    ) {}

    /**
     * The request on which the controllers leave the history to their caller.
     */
    public static function defer(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withAttribute(self::class, true);
    }

    public static function deferred(ServerRequestInterface $request): bool
    {
        return $request->getAttribute(self::class) === true;
    }

    /**
     * The 301 to the public entry that had $slug in $collection and $locale (to its markdown twin with $markdown), or
     * null.
     */
    public function entry(ServerRequestInterface $request, Collection $collection, string $locale, string $slug, bool $markdown = false): ?ResponseInterface
    {
        $id = $this->history->entryId($collection->name, $locale, $slug);
        $entry = $id === null ? null : $this->entries->find($id);
        if ($entry === null || $entry->slug === $slug || !$this->content->isPublic($entry)) {
            return null;
        }

        return $this->redirect($request, $this->entryUrls->path($entry, markdown: $markdown));
    }

    /**
     * The 301 to the term that had $slug in $taxonomy and $locale (to its markdown twin with $markdown), or null (a
     * "?page=N" stays in the query).
     */
    public function term(ServerRequestInterface $request, string $taxonomy, string $locale, string $slug, bool $markdown = false): ?ResponseInterface
    {
        $id = $this->history->termId($taxonomy, $locale, $slug);
        $term = $id === null ? null : $this->terms->find($id);
        if ($term === null || $term->slug === $slug) {
            return null;
        }

        return $this->redirect($request, $this->termUrls->path($term, markdown: $markdown));
    }

    /**
     * A 301 to $path with the request's query string (campaign tags survive), or null without a path.
     */
    private function redirect(ServerRequestInterface $request, ?string $path): ?ResponseInterface
    {
        if ($path === null) {
            return null;
        }
        $query = $request->getUri()->getQuery();

        return $this->responses->redirect($path . ($query === '' ? '' : '?' . $query), 301);
    }
}
