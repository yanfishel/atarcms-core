<?php

declare(strict_types=1);

namespace AtarCms\Admin\Menu;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Dialog;
use AtarCms\Admin\Flash;
use AtarCms\Admin\FormErrors;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\User;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryListing;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\Menu\MenuItem;
use AtarCms\Content\Menu\MenuItemInput;
use AtarCms\Content\Menu\MenuRepository;
use AtarCms\Content\Menu\MenuService;
use AtarCms\Content\Menu\MenuTarget;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermTree;
use AtarCms\Content\TreeNode;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\Locales;
use AtarCms\View\ThemeConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Menu items: the new/edit form with entry and term pickers ("?parent=" adds inside an item), and delete with a
 * confirmation page. Requested for the dialog (Dialog, the menu screen), the form and the confirmation are the
 * dialog's fragments, and a done save or delete sends the browser back to the menu screen with its flash. The tree
 * editor (MenusController) places the items. Routes require "menus.manage".
 *
 * @phpstan-import-type MenuItemFormValues from MenuItemFormData
 */
final class MenuItemsController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly MenuRequest $menuRequest,
        private readonly MenuRepository $menus,
        private readonly MenuService $service,
        private readonly ThemeConfig $theme,
        private readonly Locales $locales,
        private readonly EntryAccess $access,
        private readonly Collections $collections,
        private readonly EntryListing $listing,
        private readonly EntryRepository $entries,
        private readonly TermRepository $terms,
        private readonly MenuItemLabels $labels,
    ) {}

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $location = $this->menuRequest->location($request);
        $locale = $this->menuRequest->contentLocale($request);
        $values = MenuItemForm::defaults();
        // "?parent=" (a row's "Add inside") preselects an item of this location and language as the parent; anything
        // else is ignored.
        $parent = $request->getQueryParams()['parent'] ?? null;
        if (is_string($parent) && ctype_digit($parent)) {
            $item = $this->menus->find((int) $parent);
            if ($item !== null && $item->location === $location && $item->locale === $locale) {
                $values['parent_id'] = (string) $item->id;
            }
        }

        return $this->form($request, $location, null, $locale, $values, [], [], '');
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $location = $this->menuRequest->location($request);
        $data = MenuItemForm::parse($request);
        $locale = $this->menuRequest->locale($data->locale);
        $user = CurrentUser::require($request);
        $errors = [...$data->errors, ...$this->accessErrors($user, $data->input, null)];
        $params = [];
        if ($errors === []) {
            try {
                $this->service->create($location, $locale, $data->input, $user, ClientIp::of($request));
                Flash::success(Session::of($request), 'admin.menus.flash.created');

                return $this->done($request, $location, $locale);
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->form($request, $location, null, $locale, $data->values, $errors, $params, '', 422);
    }

    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $location = $this->menuRequest->location($request);
        $item = $this->menuRequest->item($request, $location);

        return $this->form($request, $location, $item, $item->locale, MenuItemForm::values($item), [], [], self::version($item));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $location = $this->menuRequest->location($request);
        $item = $this->menuRequest->item($request, $location);
        $data = MenuItemForm::parse($request);
        $user = CurrentUser::require($request);
        $errors = $data->version !== self::version($item)
            ? ['item' => 'validation.menu.stale']
            : [...$data->errors, ...$this->accessErrors($user, $data->input, $item)];
        $params = [];
        if ($errors === []) {
            try {
                $this->service->update($item, $data->input, $user, ClientIp::of($request));
                Flash::success(Session::of($request), 'admin.menus.flash.saved');

                return $this->done($request, $location, $item->locale);
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->form($request, $location, $item, $item->locale, $data->values, $errors, $params, $data->version, 422);
    }

    public function confirmDelete(ServerRequestInterface $request): ResponseInterface
    {
        $location = $this->menuRequest->location($request);
        $item = $this->menuRequest->item($request, $location);

        return $this->responses->html($this->view->render(Dialog::requested($request) ? 'menus/delete-dialog' : 'menus/delete', $request, [
            'location' => $location,
            'item' => $item,
            'label' => $this->labels->label($item),
            'subitems' => $this->menus->descendantCount($item),
        ]));
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $location = $this->menuRequest->location($request);
        $item = $this->menuRequest->item($request, $location);
        $this->service->delete($item, CurrentUser::require($request), ClientIp::of($request));
        Flash::success(Session::of($request), 'admin.menus.flash.deleted');

        return $this->done($request, $location, $item->locale);
    }

    /**
     * A newly chosen entry must belong to a collection the user can view; the entry the item already links to stays
     * allowed, so an item keeps working for users who cannot view its collection (like relations).
     *
     * @return array<string, string>
     */
    private function accessErrors(User $user, MenuItemInput $input, ?MenuItem $item): array
    {
        if ($input->target !== MenuTarget::Entry || $input->entryId === null || $input->entryId === $item?->entryId) {
            return [];
        }
        $entry = $this->entries->find($input->entryId);

        return $entry !== null && !$this->access->canView($user, $entry->collection) ? ['entry_id' => 'validation.menu.entry'] : [];
    }

    /**
     * @param MenuItemFormValues $values
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function form(
        ServerRequestInterface $request,
        string $location,
        ?MenuItem $item,
        string $locale,
        array $values,
        array $errors,
        array $params,
        string $version,
        int $status = 200,
    ): ResponseInterface {
        $dialog = Dialog::requested($request);
        // In the dialog a refusal without a field (a stale form, an unsupported language, a crafted post) reloads the
        // screen with it as an error toast, like the term dialog; field errors come back into the dialog.
        [, $toasts] = FormErrors::split($errors, $params, MenuItemForm::FIELDS);
        if ($toasts !== [] && $dialog) {
            foreach ($toasts as $toast) {
                Flash::error(Session::of($request), $toast->key, $toast->params);
            }

            return Dialog::redirect($this->responses, $this->menuUrl($location, $locale));
        }
        $user = CurrentUser::require($request);
        $entryGroups = [];
        foreach ($this->access->viewable($user, $this->collections) as $collection) {
            if ($collection->route === null) {
                continue;
            }
            $readLocale = $collection->translatable ? $locale : $this->locales->default();
            $entryGroups[$collection->name] = ['label' => $collection->label, 'options' => $this->listing->titles($collection->name, $readLocale)];
        }
        // The entry the item links to stays selectable: past the picker's limit with its title, and in a collection
        // the user cannot view as "#id" only.
        $linkedId = $item?->entryId;
        $linked = $linkedId === null ? null : $this->entries->find($linkedId);
        if ($linked !== null && !isset($entryGroups[$linked->collection->name]['options'][$linked->id])) {
            $name = $linked->collection->name;
            $entryGroups[$name] ??= ['label' => $linked->collection->label, 'options' => []];
            $entryGroups[$name]['options'][$linked->id] = $this->access->canView($user, $linked->collection) ? $linked->title : '#' . $linked->id;
        }
        $termGroups = [];
        foreach ($this->collections->taxonomies->all() as $taxonomy) {
            if ($taxonomy->route !== null) {
                $termGroups[$taxonomy->name] = ['label' => $taxonomy->label, 'nodes' => TermTree::flatten($this->terms->forTaxonomy($taxonomy->name, $locale))];
            }
        }

        $parents = $this->parents($location, $locale, $item);
        // A new item's heading names a parent it can go into ("Add inside"). A refused or unoffered parent is no
        // option, so the select shows the top level and the next submit goes through (like the sections' form).
        $parentLabel = $item === null && !isset($errors['parent_id']) ? ($parents[(int) $values['parent_id']]['label'] ?? null) : null;

        return $this->responses->html($this->view->render($dialog ? 'menus/dialog' : 'menus/form', $request, [
            'location' => $location,
            'locationLabel' => $this->theme->menus[$location] ?? $location,
            'item' => $item,
            'label' => $item === null ? '' : $this->labels->label($item),
            'parentLabel' => $parentLabel,
            'parents' => $parents,
            'locale' => $locale,
            'values' => $values,
            'errors' => $errors,
            'params' => $params,
            'version' => $version,
            'entryGroups' => $entryGroups,
            'termGroups' => $termGroups,
        ]), $dialog ? 200 : $status);
    }

    /**
     * After a save or a delete: the menu screen of the item's language, where the change shows (from the dialog
     * through HX-Redirect).
     */
    private function done(ServerRequestInterface $request, string $location, string $locale): ResponseInterface
    {
        $url = $this->menuUrl($location, $locale);

        return Dialog::requested($request) ? Dialog::redirect($this->responses, $url) : $this->responses->redirect($url, 303);
    }

    /**
     * The "Inside" options: the location's items of $locale in tree order, each with its depth, leaving out $item, its
     * subitems and every item too deep to hold it (a new item takes one level, an edited one its subtree's levels).
     *
     * @return array<int, array{label: string, depth: int}> item id => its option, in tree order
     */
    private function parents(string $location, string $locale, ?MenuItem $item): array
    {
        $items = $this->menus->forLocation($location, $locale);
        $labels = $this->labels->of($items);
        $height = $item === null ? 1 : self::height(self::find(TreeOrder::nest($items), $item->id));
        $options = [];
        $walk = function (array $nodes, int $depth) use (&$walk, &$options, $labels, $height, $item): void {
            /** @var list<TreeNode> $nodes */
            foreach ($nodes as $node) {
                if ($node->id === $item?->id) {
                    continue;
                }
                if ($depth + 1 + $height <= MenuService::MAX_DEPTH) {
                    $options[$node->id] = ['label' => $labels[$node->id]['label'] ?? '#' . $node->id, 'depth' => $depth];
                }
                $walk($node->children, $depth + 1);
            }
        };
        $walk(TreeOrder::nest($items), 0);

        return $options;
    }

    /**
     * @param list<TreeNode> $nodes
     */
    private static function find(array $nodes, int $id): ?TreeNode
    {
        foreach ($nodes as $node) {
            $found = $node->id === $id ? $node : self::find($node->children, $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * How many levels a node and its children take (1 for none).
     */
    private static function height(?TreeNode $node): int
    {
        $height = 1;
        foreach ($node->children ?? [] as $child) {
            $height = max($height, 1 + self::height($child));
        }

        return $height;
    }

    private function menuUrl(string $location, string $locale): string
    {
        return $this->router->url('admin.menus.show', ['location' => $location, 'locale' => $locale]);
    }

    private static function version(MenuItem $item): string
    {
        return $item->updatedAt->format('Y-m-d H:i:s');
    }
}
