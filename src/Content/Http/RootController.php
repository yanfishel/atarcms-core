<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermUrls;
use AtarCms\Http\Exception\NotFound;
use AtarCms\I18n\RequestLocale;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The root of the site, owned by the taxonomy routed at "/{slug}" (the sections), a collection routed there too (the
 * pages) and the collections routed "/{sections}/{slug}" (the articles): one route, "/{slug}" with every chain of
 * segments when there are sections ("/about/team/hello"). A path answers, in this order:
 * 1. a page: one segment, an entry of the root collection that is public (or previewed);
 * 2. a term whose trail is the path: the section's page (or its redirect to its first leaf);
 * 3. an entry of a sectioned collection whose slug is the last segment: its page, or a redirect to its canonical URL
 *    when the sections before it are wrong or missing;
 * 4. a term whose slug is the last segment: a redirect to its trail; else 404.
 * ReservedSlugs keeps page, section and article slugs apart, so the order only matters for data written around it.
 * The root route's markdown twin ("{path}.md", MarkdownPages) resolves a path the same way, every step answering
 * with its twin (EntryMarkdown for pages and articles, ListingMarkdown for sections) and every redirect going to a
 * twin.
 */
final class RootController
{
    public function __construct(
        private readonly ContentController $entries,
        private readonly TermController $terms,
        private readonly TermRepository $repository,
        private readonly TermUrls $urls,
        private readonly Collections $collections,
        private readonly SlugRedirects $redirects,
        private readonly MarkdownPages $markdownPages,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $request->getAttribute(Taxonomy::class);
        $path = $request->getAttribute('slug');
        if (!is_string($taxonomy) || !is_string($path)) {
            throw new LogicException('The root route carries its taxonomy name and a {slug}; register it with ContentModule::registerRoutes()');
        }
        $segments = explode('/', $path);
        $last = $segments[array_key_last($segments)];
        $locale = RequestLocale::of($request)->code;
        // Every current owner is tried before any previous slug (SlugRedirects): a live page, section or article wins.
        $live = SlugRedirects::defer($request);
        $page = count($segments) === 1 && is_string($request->getAttribute(Collection::class)) ? $this->collections->find((string) $request->getAttribute(Collection::class)) : null;
        // The markdown twin of the root route resolves the same way, each controller answering with its twin. A site
        // hidden from search engines has no twins, and no previous slug of one answers either.
        $markdown = $this->markdownPages->requested($request);
        if ($page !== null) {
            try {
                return $this->entries->show($live);
            } catch (NotFound) {
                // No such page: a section, an article or nothing.
            }
        }
        $term = $this->repository->findBySlug($taxonomy, $locale, $last);
        if ($term !== null && $this->urls->slugPath($term) === $path) {
            return $this->terms->show($request);
        }
        $sectioned = array_filter($this->collections->all(), static fn(Collection $collection): bool => $collection->routeTaxonomy === $taxonomy);
        foreach ($sectioned as $collection) {
            try {
                return $this->entries->show($live
                    ->withAttribute(Collection::class, $collection->name)
                    ->withAttribute($taxonomy, implode('/', array_slice($segments, 0, -1)))
                    ->withAttribute('slug', $last));
            } catch (NotFound) {
                // Not an article of this collection.
            }
        }
        try {
            return $this->terms->show($live);
        } catch (NotFound $notFound) {
            // Nothing answers: a previous slug of a page, a section or an article, in that order (a twin's: to the
            // current twin).
            $moved = ($page === null ? null : $this->redirects->entry($request, $page, $locale, $last, $markdown))
                ?? $this->redirects->term($request, $taxonomy, $locale, $last, $markdown);
            foreach ($sectioned as $collection) {
                $moved ??= $this->redirects->entry($request, $collection, $locale, $last, $markdown);
            }

            return $moved ?? throw $notFound;
        }
    }
}
