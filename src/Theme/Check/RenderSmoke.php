<?php

declare(strict_types=1);

namespace AtarCms\Theme\Check;

use AtarCms\Content\Collections;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\PublicContent;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermUrls;
use AtarCms\I18n\Locales;
use AtarCms\I18n\LocalizedUrls;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ErrorHandling\PhpErrorHandler;
use AtarCms\Kernel\Paths;
use AtarCms\View\ThemeResolver;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The render part of theme:check: requests the public pages of the current content through the whole HTTP stack of
 * an application booted with the theme, in every language: the home page, each single page, archive, the newest entry
 * of each routed collection, the first term of each routed taxonomy, the search and a missing page. A 5xx, a PHP
 * warning, a logged error or a missing page is an error; a page without <html lang>, with no or several <h1> or
 * with an <img> without alt is a warning. The application logs into a RecordingLogger, so nothing is written.
 */
final class RenderSmoke
{
    private const string MISSING = '/atar-theme-check-missing-page';

    private const int REDIRECTS = 3;

    public function run(Application $app, Report $report): void
    {
        $container = $app->container();
        $logger = new RecordingLogger();
        $container->set(LoggerInterface::class, $logger);
        $base = rtrim(str_replace('\\', '/', $container->service(Paths::class)->path()), '/') . '/';
        $appUrl = $container->service(Config::class)->appUrl();

        try {
            $pages = $this->pages($app);
        } catch (Throwable $e) {
            $report->error('render', null, null, sprintf(
                'Cannot read the content (%s): is the database reachable? Run theme:check --no-render to skip the render smoke',
                $e->getMessage(),
            ));

            return;
        }
        foreach ($pages as [$path, $note]) {
            if ($path === null) {
                $report->info('render', null, (string) $note);
            }
        }
        // As in the web front controller: a PHP warning in a template becomes an exception, so a 500 with its message.
        set_error_handler($container->service(PhpErrorHandler::class)->handle(...));
        $reporting = error_reporting(E_ALL);
        try {
            foreach ($pages as [$path, $note]) {
                if ($path !== null) {
                    $this->request($app, $logger, $report, $appUrl, $base, $path, $note === 'missing');
                }
            }
        } finally {
            error_reporting($reporting);
            restore_error_handler();
        }
    }

    /**
     * @return list<array{0: ?string, 1: ?string}> [path, null] to request, [null, note] for a page not requested,
     *                                             [path, 'missing'] for the page that must be a 404
     */
    private function pages(Application $app): array
    {
        $container = $app->container();
        $locales = $container->service(Locales::class);
        $collections = $container->service(Collections::class);
        $content = $container->service(PublicContent::class);
        $entryUrls = $container->service(EntryUrls::class);
        $termUrls = $container->service(TermUrls::class);
        $terms = $container->service(TermRepository::class);
        $urls = $container->service(LocalizedUrls::class);
        $appUrl = $container->service(Config::class)->appUrl();
        $hasSearch = $collections->searchable() !== [] && $container->service(ThemeResolver::class)->find('search') !== null;

        $pages = [];
        $seen = [];
        $add = static function (string $path) use (&$pages, &$seen): void {
            if (!isset($seen[$path])) {
                $seen[$path] = true;
                $pages[] = [$path, null];
            }
        };
        foreach ($locales->all() as $locale) {
            $prefix = $locale === $locales->default() ? '' : '/' . $locale;
            $add($prefix === '' ? '/' : $prefix);
            $word = null;
            foreach ($collections->all() as $collection) {
                if ($collection->route === null) {
                    continue;
                }
                if ($collection->single) {
                    $add($urls->path('content.' . $collection->name . '.show', [], $locale));

                    continue;
                }
                $archive = $entryUrls->archive($collection, $locale);
                if ($archive !== null) {
                    $add(substr($archive, strlen($appUrl)) ?: '/');
                }
                $entry = $content->query($collection, $locale)->first();
                $path = $entry === null ? null : $entryUrls->path($entry);
                if ($path === null) {
                    $pages[] = [null, sprintf('%s (%s): no public entries, its entry page was not rendered', $collection->name, $locale)];

                    continue;
                }
                $add($path);
                $word ??= self::word($entry->title);
            }
            foreach ($collections->taxonomies->all() as $taxonomy) {
                if ($taxonomy->route === null) {
                    continue;
                }
                $term = $terms->forTaxonomy($taxonomy->name, $locale)[0] ?? null;
                $path = $term === null ? null : $termUrls->path($term);
                if ($path === null) {
                    $pages[] = [null, sprintf('%s (%s): no terms, its term page was not rendered', $taxonomy->name, $locale)];

                    continue;
                }
                $add($path);
            }
            if ($hasSearch) {
                $add($urls->path('search', [], $locale) . '?q=' . rawurlencode($word ?? 'site'));
            }
            $pages[] = [$prefix . self::MISSING, 'missing'];
        }

        return $pages;
    }

    private function request(Application $app, RecordingLogger $logger, Report $report, string $appUrl, string $base, string $path, bool $missing): void
    {
        $factory = new Psr17Factory();
        $url = $path;
        $response = null;
        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $request = $factory->createServerRequest('GET', $appUrl . $url, ['REMOTE_ADDR' => '127.0.0.1'])->withQueryParams($query);
            try {
                $response = $app->handle($request);
            } catch (Throwable $e) {
                $report->error('render', $path, null, 'The request failed: ' . $e->getMessage());

                return;
            }
            $location = $response->getHeaderLine('Location');
            if ($response->getStatusCode() < 300 || $response->getStatusCode() >= 400 || $location === '') {
                break;
            }
            $url = str_starts_with($location, $appUrl) ? (substr($location, strlen($appUrl)) ?: '/') : $location;
            if (!str_starts_with($url, '/')) {
                break;
            }
        }
        $status = $response->getStatusCode();
        $report->page($path, $status);
        $logged = array_map(static fn(string $record): string => str_replace($base, '', $record), $logger->take());
        if ($status >= 500) {
            $report->error('render', $path, null, sprintf('%d: %s', $status, $logged === [] ? 'no message was logged' : implode('; ', $logged)));

            return;
        }
        foreach ($logged as $record) {
            $report->error('render', $path, null, $record);
        }
        if ($missing) {
            if ($status !== 404) {
                $report->error('render', $path, null, sprintf('A missing page answered %d instead of 404', $status));
            }

            return;
        }
        if ($status !== 200) {
            $report->error('render', $path, null, sprintf('Answered %d instead of 200', $status));

            return;
        }
        $html = (string) $response->getBody();
        if (preg_match('/<html\b[^>]*\blang="[^"]+"/i', $html) !== 1) {
            $report->warning('render', $path, null, 'No <html lang>: print it with $this->langAttrs($this->locale())');
        }
        $headings = preg_match_all('/<h1\b/i', $html);
        if ($headings !== 1) {
            $report->warning('render', $path, null, sprintf('%d <h1> elements: a page has exactly one', $headings));
        }
        if (preg_match('/<img(?![^>]*\salt=)[^>]*>/i', $html) === 1) {
            $report->warning('render', $path, null, 'An <img> without alt (decorative images: alt="")');
        }
    }

    /**
     * A word of a title to search for: the first of at least four letters.
     */
    private static function word(string $title): ?string
    {
        return preg_match('/\p{L}{4,}/u', $title, $match) === 1 ? $match[0] : null;
    }
}
