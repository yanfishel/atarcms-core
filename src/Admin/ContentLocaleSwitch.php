<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Admin\Taxonomy\TermRoutes;
use AtarCms\Auth\Authorizer;
use AtarCms\Auth\User;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\TermRepository;
use AtarCms\Http\Exception\HttpException;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\SafeRedirect;

/**
 * Where the top bar's content language switch leads: the screen it was used on, in the new language. The screen is
 * the posted "return" (a path of /admin on this site; anything else is the dashboard), matched as a GET route:
 *  - the form, versions or delete confirmation of an entry or term goes to its translation in the new language when
 *    the user may edit it; otherwise to the list in the new language with ?translate={id}, where the list offers to
 *    create the translation (a single collection stays on its own form with ?translate={locale}; in the linked mode,
 *    with the right to translate, the single's translation is created and its form opens);
 *  - a menu item's form or delete confirmation goes to its menu (menu items have no translations);
 *  - every other screen reloads without the parameters that belong to one language (locale, page, term, parent,
 *    translate); the remembered content language does the rest.
 */
final readonly class ContentLocaleSwitch
{
    /** Query parameters that name something of one language. */
    private const array PER_LANGUAGE = ['locale', 'page', 'term', 'parent', 'translate'];

    public function __construct(
        private Router $router,
        private Collections $collections,
        private EntryRepository $entries,
        private EntryAccess $access,
        private Taxonomies $taxonomies,
        private TermRepository $terms,
        private Authorizer $authorizer,
        private LinkedLanguage $linked,
        private EntryService $service,
    ) {}

    public function target(User $user, mixed $return, string $locale, ?string $ip = null): string
    {
        $dashboard = $this->router->url('admin.dashboard');
        $parts = parse_url(SafeRedirect::sanitize($return, $dashboard));
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        if ($path !== '/admin' && !str_starts_with($path, '/admin/')) {
            return $dashboard;
        }
        parse_str($parts['query'] ?? '', $query);
        try {
            $match = $this->router->match('GET', $path);
        } catch (HttpException) {
            return $dashboard;
        }
        // A path no admin screen answers can still match a public route (the sections' "/{slug}" chain).
        if (!str_starts_with((string) $match->route->name, 'admin.')) {
            return $dashboard;
        }
        $params = $match->params;
        $same = self::withQuery($path, array_diff_key($query, array_flip(self::PER_LANGUAGE)));

        return match ($match->route->name) {
            'admin.entries.edit', 'admin.entries.revisions', 'admin.entries.delete.confirm' => $this->entry($user, $params, $locale, $ip) ?? $same,
            // Terms only for users who may manage them (the routes' permission): nothing is looked up for others.
            'admin.terms.edit', 'admin.terms.delete.confirm', 'admin.sections.edit', 'admin.sections.delete.confirm' => ($this->authorizer->can($user, Taxonomy::PERMISSION) ? $this->term((string) $match->route->name, $params, $locale) : null) ?? $same,
            // The articles of a section belong to its language: the Sections screen of the other one instead.
            'admin.sections.articles' => $this->router->url('admin.sections', ['locale' => $locale]),
            'admin.menus.edit', 'admin.menus.delete.confirm' => $this->router->url('admin.menus.show', ['location' => $params['location'] ?? '', 'locale' => $locale]),
            default => $same,
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function entry(User $user, array $params, string $locale, ?string $ip): ?string
    {
        $collection = $this->collections->find($params['collection'] ?? '');
        $id = $params['id'] ?? '';
        $entry = strlen($id) <= 10 && ctype_digit($id) ? $this->entries->find((int) $id) : null;
        if ($collection === null || $entry === null || $entry->collection->name !== $collection->name || !$collection->translatable || $entry->locale === $locale || !$this->access->canView($user, $collection)) {
            return null;
        }
        foreach ($this->entries->translations($entry->translationGroup) as $translation) {
            if ($translation->locale === $locale && $this->access->canEdit($user, $translation)) {
                return $this->router->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $translation->id, 'locale' => $locale]);
            }
        }

        // The linked mode speaks one language per screen: a single's missing translation is created right away (the
        // toast's "Copy for translation") and its form opens, so no screen mixes two languages and nothing unlinked is
        // saved. The form offers the machine translation (Plan 38), which runs only on a click: nobody chose it yet.
        if ($collection->single && $this->linked->enabled() && $this->access->canTranslate($user, $entry)) {
            $translation = $this->service->createTranslation($entry, $locale, $user, $ip);

            return $this->router->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $translation->id, 'locale' => $locale, ...MachineTranslation::query($entry->locale, false)]);
        }

        return $collection->single
            ? $this->router->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $entry->id, 'translate' => $locale])
            : $this->router->url('admin.entries', ['collection' => $collection->name, 'locale' => $locale, 'translate' => $entry->id]);
    }

    /**
     * @param array<string, string> $params
     */
    private function term(string $route, array $params, string $locale): ?string
    {
        $id = $params['id'] ?? '';
        $term = strlen($id) <= 10 && ctype_digit($id) ? $this->terms->find((int) $id) : null;
        $taxonomy = $term === null ? null : $this->taxonomies->find($term->taxonomy);
        if ($term === null || $taxonomy === null || $term->locale === $locale) {
            return null;
        }
        // The term must belong to the screen it was shown on (the sections under /admin/sections, the others under
        // their taxonomy's URL).
        [$list, $listParams] = TermRoutes::of($taxonomy);
        if (!str_starts_with($route, $list . '.') || ($listParams['taxonomy'] ?? null) !== ($params['taxonomy'] ?? null)) {
            return null;
        }
        // Tags are kept separately per language (Plan 35): the switch shows that language's tags, nothing to translate.
        if ($this->collections->onlyTagged($taxonomy->name)) {
            return $this->router->url(...TermRoutes::of($taxonomy, '', ['locale' => $locale]));
        }
        foreach ($this->terms->translations($term->translationGroup) as $translation) {
            if ($translation->locale === $locale) {
                return $this->router->url(...TermRoutes::of($taxonomy, '.edit', ['id' => $translation->id, 'locale' => $locale]));
            }
        }

        return $this->router->url(...TermRoutes::of($taxonomy, '', ['locale' => $locale, 'translate' => $term->id]));
    }

    /**
     * @param array<mixed> $query
     */
    private static function withQuery(string $path, array $query): string
    {
        $string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $string === '' ? $path : $path . '?' . $string;
    }
}
