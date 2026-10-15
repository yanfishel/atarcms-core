<?php

declare(strict_types=1);

namespace AtarCms\Admin\Menu;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\TreeForm;
use AtarCms\Admin\TreeResponder;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Menu\MenuItem;
use AtarCms\Content\Menu\MenuRepository;
use AtarCms\Content\Menu\MenuService;
use AtarCms\Content\TreeNode;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\View\ThemeConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The menu screen: one location's tree per locale in the tree editor, whose rows link their Add inside, Edit and
 * Delete to MenuItemsController (opened in the screen's dialog). Routes require "menus.manage".
 */
final class MenusController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly MenuRequest $menuRequest,
        private readonly MenuRepository $menus,
        private readonly MenuService $service,
        private readonly ThemeConfig $theme,
        private readonly MainMenu $mainMenu,
        private readonly MenuItemLabels $labels,
        private readonly TreeResponder $tree,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $location = array_key_first($this->mainMenu->menuLocations($this->theme)) ?? throw new NotFound('The theme declares no menu locations besides the sections');

        return $this->responses->redirect($this->router->url('admin.menus.show', ['location' => $location]));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $location = $this->menuRequest->location($request);
        $locale = $this->menuRequest->contentLocale($request);

        return $this->page($request, $location, $locale, [], [], 200);
    }

    public function order(ServerRequestInterface $request): ResponseInterface
    {
        $location = $this->menuRequest->location($request);
        $form = TreeForm::parse($request);
        $locale = $this->menuRequest->locale($form->locale);
        $errors = $form->errors;
        $params = [];
        if ($form->tree !== null) {
            try {
                $this->service->reorder($location, $locale, $form->tree, $form->version, CurrentUser::require($request), ClientIp::of($request));

                return $this->tree->saved(
                    $request,
                    fn(): string => TreeOrder::version($this->menus->forLocation($location, $locale)),
                    $this->router->url('admin.menus.show', ['location' => $location, 'locale' => $locale]),
                );
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->tree->failed($request, $errors, $params, fn(): ResponseInterface => $this->page($request, $location, $locale, $errors, $params, 422));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function page(ServerRequestInterface $request, string $location, string $locale, array $errors, array $params, int $status): ResponseInterface
    {
        $items = $this->menus->forLocation($location, $locale);
        $tree = TreeOrder::nest($items);

        return $this->responses->html($this->view->render('menus/show', $request, [
            'location' => $location,
            'locations' => $this->mainMenu->menuLocations($this->theme),
            'locale' => $locale,
            'tree' => $tree,
            'items' => $this->describe($items, self::depths($tree)),
            'version' => TreeOrder::version($items),
            'maxDepth' => MenuService::MAX_DEPTH,
            'errors' => $errors,
            'params' => $params,
        ]), $status);
    }

    /**
     * What the tree editor shows per item: the label (or the target's title) as plain text, since an item has no page
     * of its own; the target as meta when the item has its own label; whether the public menu leaves it out; and its
     * Add inside (while a child still fits MAX_DEPTH levels), Edit and Delete, which open in the screen's dialog.
     *
     * @param list<MenuItem> $items
     * @param array<int, int> $depths item id => its nesting level (0 for the top level)
     *
     * @return array<int, array{label: string, url: string|null, meta: string, hidden: bool, actions: array{add: string|null, edit: string|null, delete: string|null}}>
     */
    private function describe(array $items, array $depths): array
    {
        $labels = $this->labels->of($items);
        $described = [];
        foreach ($items as $item) {
            $described[$item->id] = [
                'label' => $labels[$item->id]['label'],
                'url' => null,
                'meta' => $item->label !== '' ? $labels[$item->id]['target'] : '',
                'hidden' => $labels[$item->id]['hidden'],
                'actions' => [
                    'add' => ($depths[$item->id] ?? 0) + 2 <= MenuService::MAX_DEPTH
                        ? $this->router->url('admin.menus.new', ['location' => $item->location, 'locale' => $item->locale, 'parent' => $item->id])
                        : null,
                    'edit' => $this->router->url('admin.menus.edit', ['location' => $item->location, 'id' => $item->id]),
                    'delete' => $this->router->url('admin.menus.delete.confirm', ['location' => $item->location, 'id' => $item->id]),
                ],
            ];
        }

        return $described;
    }

    /**
     * @param list<TreeNode> $nodes
     *
     * @return array<int, int> item id => its nesting level (0 for the top level)
     */
    private static function depths(array $nodes, int $depth = 0): array
    {
        $depths = [];
        foreach ($nodes as $node) {
            $depths[$node->id] = $depth;
            $depths += self::depths($node->children, $depth + 1);
        }

        return $depths;
    }
}
