<?php

declare(strict_types=1);

namespace AtarCms\Search;

use AtarCms\Cli\Command\SearchReindexCommand;
use AtarCms\Http\Routing\Router;
use AtarCms\Kernel\Module;
use AtarCms\Search\Http\SearchController;

/**
 * The site search: /search over the search_index table, which EntryService keeps current (SearchIndexer). A core
 * module registered before ContentModule like every module: an entry of a collection routed at "/{slug}" cannot take
 * the slug "search" (ReservedSlugs).
 */
final class SearchModule extends Module
{
    public function routes(Router $router): void
    {
        $router->get('/search', [SearchController::class, 'index'], 'search');
    }

    public function commands(): array
    {
        return [SearchReindexCommand::class];
    }
}
