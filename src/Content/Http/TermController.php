<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\Markdown\ListingMarkdown;
use AtarCms\Content\Markdown\MarkdownTwin;
use AtarCms\Content\PageSeo;
use AtarCms\Content\PublicContent;
use AtarCms\Content\StructuredData\Breadcrumbs;
use AtarCms\Content\StructuredData\PageTrail;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermTree;
use AtarCms\Content\TermUrls;
use AtarCms\Http\CanonicalUrl;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\I18n\Alternates;
use AtarCms\I18n\RequestLocale;
use AtarCms\View\Renderer;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public term pages of routed taxonomies. Templates: taxonomies/{taxonomy}/show.php, falling back to term.php;
 * they receive $taxonomy, $term, $page (an EntryPage; ?page=N selects a page) and $seo (PageSeo: a section's title
 * and description, resolved along its parents; a term of another taxonomy gets its name and no description). The term is found by the last
 * segment of {slug}; in a root tree (sections) {slug} must be its trail, and a partial or wrong chain redirects there
 * (301). In a taxonomy that redirectToFirstChild(), a term with children redirects to its first leaf instead.
 * A term page's markdown twin (a MarkdownPages::twin() route, "{address}.md", "?page=N" alike) resolves the term the
 * same way, its redirects (previous slug, wrong chain, first leaf) going to twins, and answers with ListingMarkdown's
 * text; so does the page itself when the Accept header prefers markdown, its redirects staying on page addresses
 * (MarkdownPages: noindex on the twin, "Vary: Accept" on both variants of the page, none of it while the site is hidden
 * from search engines).
 */
final class TermController
{
    public function __construct(
        private readonly TermRepository $terms,
        private readonly PublicContent $content,
        private readonly TermUrls $urls,
        private readonly Renderer $view,
        private readonly Responses $responses,
        private readonly Taxonomies $taxonomies,
        private readonly Breadcrumbs $breadcrumbs,
        private readonly SlugRedirects $slugRedirects,
        private readonly ListingMarkdown $listingMarkdown,
        private readonly MarkdownPages $markdownPages,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        // The route attribute holds the taxonomy's name (route attributes are plain data).
        $name = $request->getAttribute(Taxonomy::class);
        $taxonomy = is_string($name) ? $this->taxonomies->find($name) : null;
        if ($taxonomy === null) {
            throw new LogicException('Term routes carry their taxonomy name; register them with ContentModule::registerRoutes()');
        }
        $path = $request->getAttribute('slug');
        if (!is_string($path)) {
            throw new LogicException('Term routes need a {slug} parameter');
        }
        $segments = explode('/', $path);
        $slug = $segments[array_key_last($segments)];
        // The twin route answers in markdown, and so does the page when the Accept header prefers it; redirects keep
        // to the address that was asked for (the twin's, or the page's, which negotiates again).
        $twin = $this->markdownPages->requested($request);
        $negotiable = !$twin && $this->markdownPages->negotiable($request);
        $markdown = $twin || ($negotiable && MarkdownPages::prefersMarkdown($request));
        $term = $this->terms->findBySlug($taxonomy->name, RequestLocale::of($request)->code, $slug);
        if ($term === null && !SlugRedirects::deferred($request)) {
            // A previous slug of a term: its current address (a twin's: the current twin).
            $moved = $this->slugRedirects->term($request, $taxonomy->name, RequestLocale::of($request)->code, $slug, $twin);
            if ($moved !== null) {
                return $moved;
            }
        }
        if ($term === null) {
            throw new NotFound(sprintf('No %s term at "%s"', $taxonomy->name, $path));
        }
        // The trail of a section and the first leaf come from the terms of its taxonomy and locale, in one query; so do
        // the children a markdown answer lists.
        $tree = [];
        $trail = $taxonomy->isRootTree() || $taxonomy->redirectToFirstChild;
        if ($trail || ($markdown && $taxonomy->hierarchical)) {
            foreach ($this->terms->forTaxonomy($taxonomy->name, $term->locale) as $item) {
                $tree[$item->id] = $item;
            }
        }
        $number = PageNumber::fromRequest($request);
        if ($this->urls->slugPath($term, $tree) !== $path) {
            // A partial or wrong chain of sections: send visitors and search engines to the term's trail.
            return $this->responses->redirect($this->urls->path($term, $number, $tree, $twin) ?? throw new LogicException('A routed term has no URL'), 301);
        }
        if ($taxonomy->redirectToFirstChild) {
            $leaf = TermTree::firstLeaf(array_values($tree), $term);
            if ($leaf->id !== $term->id) {
                // 302: the target changes whenever the tree is reordered.
                return $this->responses->redirect($this->urls->path($leaf, 1, $tree, $twin) ?? throw new LogicException('A routed term has no URL'), 302);
            }
        }
        $page = $this->content->byTerm($term, $number, $taxonomy->perPage);
        if ($number > $page->lastPage()) {
            throw new NotFound(sprintf('Page %d of the %s term "%s" does not exist', $number, $taxonomy->name, $term->slug));
        }
        $url = $this->urls->absolute($term, $number, $tree) ?? throw new LogicException('A routed term has no URL');
        // A section inherits from its parents, read above with the whole tree; other taxonomies have no texts.
        $seo = PageSeo::forTerm($term, !$trail ? [] : array_slice(TermTree::trail(array_values($tree), $term), 0, -1));
        if ($markdown) {
            return $this->markdownPages->respond(
                $this->listingMarkdown->term($taxonomy, $term, $page, $seo, $tree, $url, $this->markdownPages->siteDescription($request)),
                $url,
                $twin,
            );
        }

        $request = $request->withAttribute(Alternates::class, $this->alternates($term));
        // The breadcrumbs of a section come from the tree read above.
        $request = PageTrail::attach(CanonicalUrl::attach($request, $url), $this->breadcrumbs->forTerm($term, $url, $tree));
        $request = MarkdownTwin::attach($request, $this->urls->path($term, $number, $tree, true));

        return $this->markdownPages->page($request, $this->responses->html($this->view->render(
            ['taxonomies/' . $taxonomy->name . '/show', 'term'],
            ['taxonomy' => $taxonomy, 'term' => $term, 'page' => $page, 'seo' => $seo],
            $request,
        )), $negotiable);
    }

    /**
     * The term and its translations, locale => path (their trails in one query).
     */
    private function alternates(Term $term): Alternates
    {
        $translations = $this->terms->translations($term->translationGroup);
        $paths = [];
        foreach ($this->urls->paths($translations) as $id => $path) {
            foreach ($translations as $translation) {
                if ($translation->id === $id) {
                    $paths[$translation->locale] = $path;
                }
            }
        }

        return new Alternates($paths);
    }
}
