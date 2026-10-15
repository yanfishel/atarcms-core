<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\User;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\Menu\MenuRepository;
use AtarCms\Content\Menu\MenuService;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\TermRepository;
use AtarCms\Http\Routing\RouteMatch;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The language of the item an admin route names (the linked mode's screen language, AdminLocale): an entry of a
 * translatable collection the user may view (an untranslated collection's entries live in the default language only
 * and lend the screen none), a term or section for those who manage them, a menu item for those who manage menus.
 * Null for any other route, an item that does not belong to the URL, or one the user may not open, so no language
 * leaks from what the route will refuse.
 */
final readonly class ItemLocale
{
    public function __construct(
        private Collections $collections,
        private EntryRepository $entries,
        private EntryAccess $access,
        private Taxonomies $taxonomies,
        private TermRepository $terms,
        private MenuRepository $menus,
        private Authorizer $authorizer,
    ) {}

    public function of(ServerRequestInterface $request, User $user): ?string
    {
        $match = RouteMatch::of($request);
        $id = $match?->params['id'] ?? '';
        if ($match === null || preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
            return null;
        }
        $route = (string) $match->route->name;
        $params = $match->params;

        return match (true) {
            str_starts_with($route, 'admin.entries.') => $this->entry((int) $id, $params['collection'] ?? '', $user),
            str_starts_with($route, 'admin.terms.') => $this->term((int) $id, $params['taxonomy'] ?? '', $user),
            str_starts_with($route, 'admin.sections.') => $this->term((int) $id, $this->taxonomies->root()->name ?? '', $user),
            str_starts_with($route, 'admin.menus.') => $this->menuItem((int) $id, $params['location'] ?? '', $user),
            default => null,
        };
    }

    private function entry(int $id, string $collectionName, User $user): ?string
    {
        $collection = $this->collections->find($collectionName);
        if ($collection === null || !$collection->translatable || !$this->access->canView($user, $collection)) {
            return null;
        }
        $entry = $this->entries->find($id);

        return $entry !== null && $entry->collection->name === $collection->name ? $entry->locale : null;
    }

    private function term(int $id, string $taxonomy, User $user): ?string
    {
        if ($taxonomy === '' || !$this->authorizer->can($user, Taxonomy::PERMISSION)) {
            return null;
        }
        $term = $this->terms->find($id);

        return $term !== null && $term->taxonomy === $taxonomy ? $term->locale : null;
    }

    private function menuItem(int $id, string $location, User $user): ?string
    {
        if (!$this->authorizer->can($user, MenuService::PERMISSION)) {
            return null;
        }
        $item = $this->menus->find($id);

        return $item !== null && $item->location === $location ? $item->locale : null;
    }
}
