<?php

declare(strict_types=1);

namespace AtarCms\Search\Http;

use AtarCms\Content\Collections;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\Http\PageNumber;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Search\SearchQuery;
use AtarCms\Search\SearchRepository;
use AtarCms\Search\SearchResult;
use AtarCms\Search\TextNormalizer;
use AtarCms\View\Renderer;
use AtarCms\View\ThemeResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /search?q=…&page=N (locale-prefixed like every public page): the public entries of the request locale that
 * contain every word of q, rendered with the theme's search.php. A theme without search.php has no search page.
 */
final class SearchController
{
    private readonly int $perPage;

    public function __construct(
        private readonly ThemeResolver $themes,
        private readonly TextNormalizer $normalizer,
        private readonly SearchRepository $index,
        private readonly Collections $collections,
        private readonly EntryRepository $entries,
        private readonly EntryUrls $urls,
        private readonly Clock $clock,
        private readonly Renderer $view,
        private readonly Responses $responses,
        Config $config,
    ) {
        $this->perPage = $config->int('content.search_per_page', 10);
        if ($this->perPage < 1 || $this->perPage > 100) {
            throw new ConfigException(sprintf('content.search_per_page must be between 1 and 100, got %d', $this->perPage));
        }
    }

    /**
     * search.php receives query (the text as typed, at most 200 characters), results (list<SearchResult>), total,
     * page, perPage, pages and tooShort (nothing to search for: the database was not asked).
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->themes->find('search') === null) {
            throw new NotFound('The theme has no search.php template');
        }
        $number = PageNumber::fromRequest($request);
        $locale = RequestLocale::of($request)->code;
        $q = $request->getQueryParams()['q'] ?? '';
        $query = SearchQuery::parse(is_string($q) ? $q : '', $locale, $this->normalizer);
        $page = $this->index->search($query, $locale, array_keys($this->collections->searchable()), $this->clock->now(), $number, $this->perPage);
        if ($number > $page->pages()) {
            throw new NotFound(sprintf('Page %d of the search results does not exist', $number));
        }

        return $this->responses->html($this->view->render('search', [
            'query' => $query->text,
            'results' => $this->results($page->ids),
            'total' => $page->total,
            'page' => $page->number,
            'perPage' => $page->perPage,
            'pages' => $page->pages(),
            'tooShort' => $query->isEmpty(),
        ], $request));
    }

    /**
     * The entries in hit order, loaded in one query (their URLs in at most two more: EntryUrls::paths()).
     *
     * @param list<int> $ids
     *
     * @return list<SearchResult>
     */
    private function results(array $ids): array
    {
        $entries = $this->entries->byIds($ids);
        $paths = $this->urls->paths($entries);
        $results = [];
        foreach ($ids as $id) {
            $entry = $entries[$id] ?? null;
            if ($entry !== null && isset($paths[$id])) {
                $results[] = new SearchResult($entry, $paths[$id], SearchResult::describe($entry));
            }
        }

        return $results;
    }
}
