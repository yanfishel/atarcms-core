<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Admin\AdminBar;
use AtarCms\Admin\AdminBarAction;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Markdown\MarkdownTwin;
use AtarCms\Content\Menu\PublicMenus;
use AtarCms\Http\RequestMemo;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Container;
use AtarCms\Settings\Settings;
use AtarCms\Settings\SiteSettings;
use AtarCms\View\Renderer;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Theme helpers for published content: entries('news'), single('settings'), entryUrl($entry), entryUrls($page->entries)
 * (entry id => URL, their ancestors and section terms loaded at once: use it in lists of sectioned entries),
 * termTrail($term) (the term and its ancestors, root first: breadcrumbs; the terms of its taxonomy and locale are read
 * once per request, and termUrl() builds the trails of sections from them), pageTrail() (the page's ancestors and the
 * page as the BreadcrumbList has them, [] without one: visible breadcrumbs that match the JSON-LD), termChildren('sections', $parent) (the
 * terms under $parent, null for the top level, in tree order, from the same per-request read),
 * editUrl($entry) (the admin edit URL when the current user may view and edit the entry, else null),
 * terms($entry, 'categories') (one entry's terms), termsFor($page->entries, 'tags') (entry id => terms, in one
 * query: use it in lists), termUrl($term, $page) (null when the taxonomy has no route; "?page=N" from page 2 on) and
 * menu('main') (the visible links of a theme menu location in the current locale, with "current" flags),
 * socialMeta($title, $description, $entry, 'large') (the page's Open Graph and Twitter card tags and its JSON-LD, see
 * SocialMeta),
 * markdownAlternate() (<link rel="alternate" type="text/markdown"> to the markdown twin of an entry, archive or term
 * page, '' on other pages, previews and while the site is hidden from search engines),
 * siteSettings() (the site settings of the current locale: SEO defaults, phone and email; AtarCms\Settings\Settings),
 * presetEntry('privacy') and presetUrl('privacy') (a preset page of Collection::presets() in the current locale and its
 * address, null while it is missing or not public; every preset is read in one query per request).
 * On the admin's preview of an unsaved entry (a PreviewEntry on the request) terms(), termsFor() and entryUrl() answer
 * for that entry from the form's values, and editUrl() is null.
 * Media helpers (mediaFor, img, mediaUrl, ogImage) live in AtarCms\Media\MediaViewHelpers.
 */
final class ContentViewHelpers
{
    public static function register(Renderer $renderer, Container $container): void
    {
        $renderer->requestHelper(
            'socialMeta',
            static fn(ServerRequestInterface $request, ?string $title, ?string $description = null, ?Entry $entry = null, ?string $imageVariant = null): string => $container
                ->service(SocialMeta::class)
                ->tags($request, $title, $description, $entry, $imageVariant),
        );
        $renderer->requestHelper(
            'markdownAlternate',
            static function (ServerRequestInterface $request) use ($container): string {
                $twin = MarkdownTwin::of($request);

                return $twin === null || $container->service(SiteSettings::class)->forRequest($request)->hideFromSearch
                    ? ''
                    : sprintf('<link rel="alternate" type="text/markdown" href="%s">', htmlspecialchars($twin->path, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
            },
        );
        $renderer->requestHelper(
            'siteSettings',
            static fn(ServerRequestInterface $request): Settings => $container->service(SiteSettings::class)->forRequest($request),
        );
        $renderer->requestHelper(
            'entries',
            static fn(ServerRequestInterface $request, string $collection): ContentQuery => $container
                ->service(PublicContent::class)
                ->query($collection, RequestLocale::of($request)->code),
        );
        $renderer->requestHelper(
            'single',
            static fn(ServerRequestInterface $request, string $collection): ?Entry => $container
                ->service(PublicContent::class)
                ->single($container->service(Collections::class)->get($collection), RequestLocale::of($request)->code),
        );
        $renderer->requestHelper(
            'presetEntry',
            static fn(ServerRequestInterface $request, string $preset): ?Entry => self::presets($request, $container)[$preset] ?? null,
        );
        $renderer->requestHelper(
            'presetUrl',
            static function (ServerRequestInterface $request, string $preset) use ($container): ?string {
                $entry = self::presets($request, $container)[$preset] ?? null;

                return $entry === null ? null : $container->service(EntryUrls::class)->path($entry);
            },
        );
        $renderer->optionalRequestHelper(
            'entryUrl',
            static function (?ServerRequestInterface $request, Entry $entry) use ($container): string {
                $urls = $container->service(EntryUrls::class);
                $preview = $request === null ? null : PreviewEntry::of($request);
                if ($preview !== null && $preview->isFor($entry)) {
                    // The unsaved entry's would-be address: its chosen parent chain and section ("#" without one).
                    $routeTerm = $preview->routeTerm();
                    $knownTerms = $routeTerm === null ? [] : $container->service(TermUrls::class)->ancestors([$routeTerm]);

                    return ($entry->collection->routeTaxonomy !== null && $routeTerm === null ? null : $urls->path($entry, $preview->parents, $routeTerm, $knownTerms)) ?? '#';
                }

                return $urls->path($entry)
                    ?? throw new LogicException(sprintf('The %s entry "%s" has no URL: its collection has no route, or it lacks the term its route needs', $entry->collection->name, $entry->slug));
            },
        );
        $renderer->helper(
            'entryUrls',
            /**
             * @param list<Entry> $entries
             *
             * @return array<int, string>
             */
            static function (array $entries) use ($container): array {
                $list = [];
                foreach ($entries as $entry) {
                    if ($entry instanceof Entry) {
                        $list[] = $entry;
                    }
                }

                return $container->service(EntryUrls::class)->paths($list);
            },
        );
        $renderer->requestHelper(
            'termTrail',
            /**
             * @return list<Term>
             */
            static fn(ServerRequestInterface $request, Term $term): array => TermTree::trail(array_values(self::taxonomyTerms($request, $container, $term)), $term),
        );
        $renderer->requestHelper(
            'pageTrail',
            /**
             * Where the page sits, as its BreadcrumbList has it (PageTrail): its ancestors (the archive, the sections
             * or the parent pages) root first and the page last, each with its absolute URL, no home page; [] on pages
             * without one (search, archives, previews).
             *
             * @return list<array{name: string, url: string}>
             */
            static fn(ServerRequestInterface $request): array => StructuredData\PageTrail::of($request)->items ?? [],
        );
        $renderer->requestHelper(
            'termChildren',
            /**
             * The terms of $taxonomy in the request's locale under $parent (null: the top level), in tree order; the
             * taxonomy is read once per request.
             *
             * @return list<Term>
             */
            static function (ServerRequestInterface $request, string $taxonomy, ?Term $parent = null) use ($container): array {
                $container->service(Taxonomies::class)->get($taxonomy);
                $locale = RequestLocale::of($request)->code;
                $terms = RequestMemo::through(
                    $request,
                    'terms:' . $taxonomy . ':' . $locale,
                    static function () use ($container, $taxonomy, $locale): array {
                        $terms = [];
                        foreach ($container->service(TermRepository::class)->forTaxonomy($taxonomy, $locale) as $item) {
                            $terms[$item->id] = $item;
                        }

                        return $terms;
                    },
                );

                return array_values(array_filter($terms, static fn(Term $term): bool => $term->parentId === $parent?->id));
            },
        );
        $renderer->requestHelper(
            'adminBar',
            /**
             * The signed-in user's quick actions on this page (AtarCms\Admin\AdminBar); empty for a visitor.
             *
             * @return list<AdminBarAction>
             */
            static fn(ServerRequestInterface $request, ?Entry $entry = null, ?Term $term = null): array => $container
                ->service(AdminBar::class)
                ->actions($request, $entry, $term),
        );
        $renderer->requestHelper(
            'editUrl',
            static function (ServerRequestInterface $request, Entry $entry) use ($container): ?string {
                if (PreviewEntry::of($request) !== null) {
                    // A preview opens from the form: no link back into another edit page.
                    return null;
                }
                $user = CurrentUser::of($request);
                $access = $container->service(EntryAccess::class);

                return $access->canView($user, $entry->collection) && $access->canEdit($user, $entry)
                    ? $container->service(Router::class)->url('admin.entries.edit', ['collection' => $entry->collection->name, 'id' => $entry->id])
                    : null;
            },
        );
        $renderer->optionalRequestHelper(
            'terms',
            static function (?ServerRequestInterface $request, Entry $entry, string $taxonomy) use ($container): array {
                $container->service(Taxonomies::class)->get($taxonomy);
                $preview = $request === null ? null : PreviewEntry::of($request);
                if ($preview !== null && $preview->isFor($entry)) {
                    return $preview->terms($taxonomy);
                }

                return $container->service(TermRepository::class)->forEntries([$entry->id], $taxonomy)[$entry->id] ?? [];
            },
        );
        $renderer->optionalRequestHelper(
            'termsFor',
            /**
             * @param list<Entry> $entries
             *
             * @return array<int, list<Term>>
             */
            static function (?ServerRequestInterface $request, array $entries, string $taxonomy) use ($container): array {
                $container->service(Taxonomies::class)->get($taxonomy);
                $preview = $request === null ? null : PreviewEntry::of($request);
                $ids = [];
                $previewed = null;
                foreach ($entries as $entry) {
                    /** @var Entry $entry */
                    if ($preview !== null && $preview->isFor($entry)) {
                        $previewed = $entry;
                    } else {
                        $ids[] = $entry->id;
                    }
                }
                $terms = $container->service(TermRepository::class)->forEntries(array_values(array_unique($ids)), $taxonomy);
                if ($preview !== null && $previewed !== null && $preview->terms($taxonomy) !== []) {
                    $terms[$previewed->id] = $preview->terms($taxonomy);
                }

                return $terms;
            },
        );
        $renderer->requestHelper(
            'termUrl',
            static function (ServerRequestInterface $request, Term $term, int $page = 1) use ($container): ?string {
                $urls = $container->service(TermUrls::class);
                // A nested section's trail needs its ancestors: they come from the terms read once per request.
                $known = $term->parentId === null ? [] : self::taxonomyTerms($request, $container, $term);

                return $urls->path($term, $page, $known);
            },
        );
        $renderer->requestHelper(
            'menu',
            // The full request path, WITH the locale prefix (e.g. "/ru/about"): entry and term URLs carry the
            // prefix too (LocalizedUrls), so this is the value PublicMenus::tree() must compare against, not the
            // prefix-stripped RequestPath.
            // Memoised per request: a layout that renders the same location twice runs its queries once.
            static function (ServerRequestInterface $request, string $location) use ($container): array {
                $locale = RequestLocale::of($request)->code;
                $path = $request->getUri()->getPath();

                return RequestMemo::through(
                    $request,
                    'menu:' . $location . ':' . $locale . ':' . $path,
                    static fn(): array => $container->service(PublicMenus::class)->tree($location, $locale, $path),
                );
            },
        );
    }

    /**
     * The public preset pages in the request's language, read once per request.
     *
     * @return array<string, Entry>
     */
    private static function presets(ServerRequestInterface $request, Container $container): array
    {
        $locale = RequestLocale::of($request)->code;

        return RequestMemo::through($request, 'presets:' . $locale, static fn(): array => $container->service(PublicContent::class)->presets($locale));
    }

    /**
     * The terms of $term's taxonomy and locale by id, read once per request.
     *
     * @return array<int, Term>
     */
    private static function taxonomyTerms(ServerRequestInterface $request, Container $container, Term $term): array
    {
        return RequestMemo::through($request, 'terms:' . $term->taxonomy . ':' . $term->locale, static function () use ($container, $term): array {
            $terms = [];
            foreach ($container->service(TermRepository::class)->forTaxonomy($term->taxonomy, $term->locale) as $item) {
                $terms[$item->id] = $item;
            }

            return $terms;
        });
    }
}
