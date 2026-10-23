<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\Markdown\EntryMarkdown;
use AtarCms\Content\Markdown\ListingMarkdown;
use AtarCms\Content\Markdown\MarkdownTwin;
use AtarCms\Content\PageSeo;
use AtarCms\Content\PublicContent;
use AtarCms\Content\StructuredData\Breadcrumbs;
use AtarCms\Content\StructuredData\PageTrail;
use AtarCms\Content\TermTree;
use AtarCms\Content\TermUrls;
use AtarCms\Http\CanonicalUrl;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\I18n\Alternates;
use AtarCms\I18n\Locales;
use AtarCms\View\Renderer;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public collection pages. Templates: collections/{collection}/{view}.php, falling back to {view}.php.
 * "?preview=1" shows drafts to users who can view the collection's entries. Entry pages also receive $seo (PageSeo:
 * the title and description resolved along the entry's sections; null on a single() page without its entry).
 * An entry page's markdown twin (a MarkdownPages::twin() route, "{address}.md") resolves its entry the same way and
 * answers with EntryMarkdown's text instead: never a preview, a previous slug and a wrong chain redirect to the
 * canonical twin, and a site hidden from search engines has none. The page itself answers with its twin's markdown
 * (without the twin's noindex: it is the canonical address) when the Accept header prefers text/markdown to
 * text/html (never while previewing or hidden), its redirects staying on page addresses; both variants send
 * "Vary: Accept". The HTML page names its twin in a Link header (rel="alternate", type="text/markdown") wherever the
 * markdownAlternate() helper prints its tag (MarkdownPages). Archives do the same with ListingMarkdown (index()).
 */
final class ContentController
{
    public function __construct(
        private readonly PublicContent $content,
        private readonly Collections $collections,
        private readonly EntryRepository $entries,
        private readonly EntryUrls $urls,
        private readonly TermUrls $termUrls,
        private readonly Renderer $view,
        private readonly Responses $responses,
        private readonly Authorizer $authorizer,
        private readonly Locales $locales,
        private readonly Breadcrumbs $breadcrumbs,
        private readonly SlugRedirects $slugRedirects,
        private readonly EntryMarkdown $entryMarkdown,
        private readonly ListingMarkdown $listingMarkdown,
        private readonly MarkdownPages $markdownPages,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $collection = ContentRequest::collection($request, $this->collections);
        $locale = ContentRequest::locale($request, $collection, $this->locales);
        // The twin route answers in markdown, and so does the page when the Accept header prefers it; redirects keep
        // to the address that was asked for (the twin's, or the page's, which negotiates again).
        $twin = $this->markdownPages->requested($request);
        $negotiable = !$twin && $this->markdownPages->negotiable($request);
        $markdown = $twin || ($negotiable && MarkdownPages::prefersMarkdown($request));
        $path = $request->getAttribute('slug');
        if (!is_string($path)) {
            throw new LogicException('Content show routes need a {slug} parameter');
        }
        $segments = explode('/', $path);
        $slug = $segments[array_key_last($segments)];
        $entry = $this->entries->findBySlug($collection->name, $locale, $slug);
        $preview = !$markdown && $this->isPreview($request, $collection);
        if ($entry === null && !$preview && !SlugRedirects::deferred($request)) {
            // A previous slug of a published entry: its current address (a twin's: the current twin).
            $moved = $this->slugRedirects->entry($request, $collection, $locale, $slug, $twin);
            if ($moved !== null) {
                return $moved;
            }
        }
        if ($entry === null || (!$preview && !$this->content->isPublic($entry))) {
            throw new NotFound(sprintf('No public %s entry at "%s"', $collection->name, $path));
        }
        // A nested page's ancestors, for its address and its breadcrumbs: the one query slugPath() would make anyway.
        $ancestors = $collection->hierarchical && $entry->parentId !== null ? $this->entries->ancestors([$entry->id]) : [];
        $routeTerm = null;
        $knownTerms = [];
        if ($collection->routeTaxonomy !== null) {
            // The entry's URL follows its term: without one it has no URL at all.
            $routeTerm = $this->urls->routeTerm($entry)
                ?? throw new NotFound(sprintf('The %s entry "%s" has no %s term', $collection->name, $entry->slug, $collection->routeTaxonomy));
            $knownTerms = $this->termUrls->ancestors([$routeTerm]);
        }
        $trail = $routeTerm === null ? null : $this->termUrls->slugPath($routeTerm, $knownTerms);
        if ($this->urls->slugPath($entry, $ancestors) !== $path || ($trail !== null && $request->getAttribute((string) $collection->routeTaxonomy) !== $trail)) {
            // Missing or wrong parent or section segments: send visitors and search engines to the canonical URL (a
            // markdown twin to the canonical twin).
            $target = $this->urls->path($entry, $ancestors, $routeTerm, $knownTerms, $twin) ?? throw new LogicException('A routed entry has no URL');

            return $preview
                ? self::private($this->responses->redirect($target . '?preview=1', 302))
                : $this->responses->redirect($target, 301);
        }
        if ($markdown) {
            return $this->markdown($request, $entry, $ancestors, (string) $this->urls->absolute($entry, $ancestors, $routeTerm, $knownTerms), $twin);
        }

        // The section texts and breadcrumbs come from the route term and its ancestors loaded above: no extra query.
        $sections = $routeTerm === null ? [] : TermTree::trail(array_values($knownTerms), $routeTerm);
        $request = $request->withAttribute(Alternates::class, $this->alternates($entry, $ancestors));
        $url = $preview ? null : $this->urls->absolute($entry, $ancestors, $routeTerm, $knownTerms);
        if ($url !== null) {
            $request = PageTrail::attach(CanonicalUrl::attach($request, $url), $this->breadcrumbs->forEntry($entry, $url, $sections, $knownTerms, $ancestors));
            $request = MarkdownTwin::attach($request, $this->urls->path($entry, $ancestors, $routeTerm, $knownTerms, markdown: true));
        }
        $seo = PageSeo::forEntry($entry, $sections);
        return $this->markdownPages->page($request, $this->render($request, $collection, 'show', ['entry' => $entry, 'seo' => $seo], $preview), $negotiable);
    }

    /**
     * A single() collection: renders even before its entry exists ($entry is then null, and the page advertises no
     * language alternates and no canonical URL: there is no entry to point to).
     */
    public function single(ServerRequestInterface $request): ResponseInterface
    {
        $collection = ContentRequest::collection($request, $this->collections);
        $locale = ContentRequest::locale($request, $collection, $this->locales);
        if ($this->markdownPages->requested($request)) {
            $entry = $this->content->single($collection, $locale)
                ?? throw new NotFound(sprintf('The single %s has no public entry in "%s"', $collection->name, $locale));

            return $this->markdown($request, $entry, [], (string) $this->urls->absolute($entry), true);
        }
        // A previewing request ("?preview=1") never negotiates; a single without its public entry stays HTML.
        $negotiable = $this->markdownPages->negotiable($request);
        $preview = $this->isPreview($request, $collection);
        $entry = $preview ? $this->entries->first($collection->name, $locale) : $this->content->single($collection, $locale);
        if ($entry !== null && $negotiable && MarkdownPages::prefersMarkdown($request)) {
            return $this->markdown($request, $entry, [], (string) $this->urls->absolute($entry), false);
        }
        $request = $request->withAttribute(Alternates::class, $entry === null ? new Alternates([]) : $this->alternates($entry));
        if ($entry !== null && !$preview) {
            $request = MarkdownTwin::attach(CanonicalUrl::attach($request, $this->urls->absolute($entry)), $this->urls->path($entry, markdown: true));
        }
        return $this->markdownPages->page(
            $request,
            $this->render($request, $collection, 'show', ['entry' => $entry, 'seo' => $entry === null ? null : PageSeo::forEntry($entry, [])], $preview),
            $negotiable,
        );
    }

    /**
     * A page of the collection's archive ("?page=N"). Its markdown twin ("{archive}.md", "?page=N" alike) and the
     * archive's own address when the Accept header prefers markdown answer with ListingMarkdown's text instead, like an
     * entry page's.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $collection = ContentRequest::collection($request, $this->collections);
        $locale = ContentRequest::locale($request, $collection, $this->locales);
        $twin = $this->markdownPages->requested($request);
        $negotiable = !$twin && $this->markdownPages->negotiable($request);
        $number = PageNumber::fromRequest($request);
        $page = $this->content->query($collection, $locale)->page($number);
        if ($number > $page->lastPage()) {
            throw new NotFound(sprintf('Page %d of %s does not exist', $number, $collection->name));
        }
        $url = (string) $this->urls->archive($collection, $locale, $number);
        if ($twin || ($negotiable && MarkdownPages::prefersMarkdown($request))) {
            return $this->markdownPages->respond(
                $this->listingMarkdown->archive($collection, $locale, $page, $url, $this->markdownPages->siteDescription($request)),
                $url,
                $twin,
            );
        }

        if (!$collection->translatable) {
            $request = $request->withAttribute(Alternates::class, new Alternates([]));
        }
        $request = MarkdownTwin::attach(CanonicalUrl::attach($request, $url), $this->urls->archivePath($collection, $locale, $number, true));

        return $this->markdownPages->page($request, $this->render($request, $collection, 'index', ['page' => $page], false), $negotiable);
    }

    /**
     * The theme's template candidates of a collection's view ("show", "index"), highest priority first; the admin's
     * preview of an unsaved entry renders the same ones.
     *
     * @return list<string>
     */
    public static function templates(Collection $collection, string $view): array
    {
        return ['collections/' . $collection->name . '/' . $view, $view];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(ServerRequestInterface $request, Collection $collection, string $view, array $data, bool $preview): ResponseInterface
    {
        $response = $this->responses->html($this->view->render(
            self::templates($collection, $view),
            ['collection' => $collection, ...$data],
            $request,
        ));

        return $preview ? self::private($response) : $response;
    }

    /**
     * A public entry page as markdown, pointing to the page ($url) as canonical: at the twin's address ($twin) kept
     * out of search indexes, at the page's own (negotiated) varying by Accept (MarkdownPages::respond()).
     *
     * @param array<int, Entry> $ancestors
     */
    private function markdown(ServerRequestInterface $request, Entry $entry, array $ancestors, string $url, bool $twin): ResponseInterface
    {
        // The entry is in the request's language (ContentRequest::locale()), so the request's settings are its.
        $document = $this->entryMarkdown->document($entry, $ancestors, $url, $this->markdownPages->siteDescription($request));

        return $this->markdownPages->respond($document, $url, $twin);
    }

    /**
     * Preview responses (pages and their redirects) are never cached or indexed.
     */
    private static function private(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store, private')->withHeader('X-Robots-Tag', 'noindex');
    }

    private function isPreview(ServerRequestInterface $request, Collection $collection): bool
    {
        return ($request->getQueryParams()['preview'] ?? null) === '1'
            && $this->authorizer->can(CurrentUser::of($request), $collection->permission('view'));
    }

    /**
     * The entry itself and its public translations, locale => path (their route terms loaded in one query).
     *
     * @param array<int, Entry> $ancestors the entry's ancestors when already loaded: an untranslated nested page then
     *                                     needs no second ancestors query
     */
    private function alternates(Entry $entry, array $ancestors = []): Alternates
    {
        $translations = [$entry, ...$this->content->translations($entry)];
        $paths = [];
        foreach ($this->urls->paths($translations, $ancestors) as $id => $path) {
            foreach ($translations as $translation) {
                if ($translation->id === $id) {
                    $paths[$translation->locale] = $path;
                }
            }
        }

        return new Alternates($paths);
    }
}
