<?php

declare(strict_types=1);

namespace AtarCms\Admin\Taxonomy;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\TranslationOffers;
use AtarCms\Admin\TreeForm;
use AtarCms\Admin\TreeResponder;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\User;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Menu\MainMenuService;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermService;
use AtarCms\Content\TreeNode;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Sections screen (/admin/sections): the sections of one locale in the tree editor, with the main menu's pinned
 * collections (MainMenu) among their top level, which can only be moved up and down there. The sections are added,
 * edited, translated and deleted through TermsController and TermActionsController under /admin/sections (TermRoutes):
 * each section's row links its "Add subsection" (the new form with ?parent=), Edit and Delete icons there; Delete is
 * disabled with the reason for a section delete() would refuse (TermService::deleteBlockers(), one query), and a
 * pinned item's three are disabled. When collections list their entries on the section pages, every row starts with
 * "Order articles" (SectionArticlesController), offered on the sections without subsections (articles belong there). An item's name opens its
 * content, where the user may see it: a section the list of the first collection routed by the sections (config
 * order) filtered by it, a pinned single its entry's form (or "Add new" before it exists), a pinned archive its list.
 * Routes require "taxonomies.manage".
 */
final class SectionsController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly TermRequest $termRequest,
        private readonly TermRepository $terms,
        private readonly MainMenu $mainMenu,
        private readonly MainMenuService $service,
        private readonly Locales $locales,
        private readonly Translator $translator,
        private readonly TreeResponder $tree,
        private readonly Collections $collections,
        private readonly EntryRepository $entries,
        private readonly EntryAccess $access,
        private readonly TermService $termService,
        private readonly TranslationOffers $offers,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $sections = $this->termRequest->taxonomy($request);

        return $this->page($request, $sections, $this->termRequest->contentLocale($request), [], [], 200);
    }

    public function order(ServerRequestInterface $request): ResponseInterface
    {
        $sections = $this->termRequest->taxonomy($request);
        $form = TreeForm::parse($request);
        $locale = $this->termRequest->locale($form->locale);
        $errors = $form->errors;
        $params = [];
        if ($form->tree !== null) {
            try {
                $this->service->reorder($sections, $locale, $form->tree, $form->version, CurrentUser::require($request), ClientIp::of($request));

                return $this->tree->saved(
                    $request,
                    fn(): string => $this->service->version($this->terms->forTaxonomy($sections->name, $locale)),
                    $this->router->url('admin.sections', ['locale' => $locale]),
                );
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->tree->failed($request, $errors, $params, fn(): ResponseInterface => $this->page($request, $sections, $locale, $errors, $params, 422));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function page(ServerRequestInterface $request, Taxonomy $sections, string $locale, array $errors, array $params, int $status): ResponseInterface
    {
        $user = CurrentUser::require($request);
        $terms = $this->terms->forTaxonomy($sections->name, $locale);
        $articles = null;
        foreach ($this->collections->all() as $collection) {
            if ($collection->routeTaxonomy === $sections->name) {
                $articles = $this->access->canView($user, $collection) ? $collection : null;

                break;
            }
        }
        // Articles of an untranslated collection exist in the default language only and take only its sections: a
        // section of another language holds none of them, so its name opens nothing.
        if ($articles !== null && !$articles->translatable && $locale !== $this->locales->default()) {
            $articles = null;
        }
        $tree = $this->service->tree($terms);
        $depths = self::depths($tree);
        $blockers = $this->termService->deleteBlockers($terms);
        $ordered = $this->collections->listedBy($sections->name) !== [];
        $parents = [];
        foreach ($terms as $term) {
            if ($term->parentId !== null) {
                $parents[$term->parentId] = true;
            }
        }
        $items = [];
        foreach ($terms as $term) {
            $parent = isset($parents[$term->id]);
            $items[$term->id] = [
                'label' => $term->name,
                'url' => $articles === null ? null : $this->router->url('admin.entries', ['collection' => $articles->name, 'locale' => $locale, 'term' => $term->id]),
                'meta' => '',
                'hidden' => false,
                'pinned' => false,
                'actions' => [
                    ...($ordered ? ['order' => $parent ? null : $this->router->url('admin.sections.articles', ['id' => $term->id])] : []),
                    // A subsection one level deeper must still fit (TreeOrder::MAX_DEPTH levels).
                    'add' => ($depths[$term->id] ?? 0) + 2 <= TreeOrder::MAX_DEPTH ? $this->router->url('admin.sections.new', ['locale' => $locale, 'parent' => $term->id]) : null,
                    'edit' => $this->router->url('admin.sections.edit', ['id' => $term->id]),
                    'delete' => isset($blockers[$term->id]) ? null : $this->router->url('admin.sections.delete.confirm', ['id' => $term->id]),
                ],
                'locked' => [
                    ...($parent ? ['order' => 'admin.sections.order_parent'] : []),
                    ...(isset($blockers[$term->id]) ? ['delete' => $blockers[$term->id]] : []),
                ],
            ];
        }
        // Labelled like the public main menu (PublicMenus): in the language of the sections shown, not the interface's.
        foreach ($this->mainMenu->pinned ?? [] as $index => $collection) {
            $items[MainMenuService::pinnedId($index)] = [
                'label' => $this->translator->label($locale, $collection->label),
                'url' => $this->contentUrl($user, $collection, $locale),
                'meta' => '',
                'hidden' => false,
                'pinned' => true,
                'actions' => [...($ordered ? ['order' => null] : []), 'add' => null, 'edit' => null, 'delete' => null],
                'locked' => [
                    ...($ordered ? ['order' => 'admin.sections.pinned'] : []),
                    'add' => 'admin.sections.pinned',
                    'edit' => 'admin.sections.pinned',
                    'delete' => 'admin.sections.pinned',
                    'outdent' => 'admin.sections.pinned_level',
                    'indent' => 'admin.sections.pinned_level',
                ],
            ];
        }

        return $this->responses->html($this->view->render('sections/index', $request, [
            'taxonomy' => $sections,
            'locale' => $locale,
            'tree' => $tree,
            'items' => $items,
            'version' => $this->service->version($terms),
            'maxDepth' => TreeOrder::MAX_DEPTH,
            'errors' => $errors,
            'params' => $params,
            // After the content language switch found no translation of the section being edited (?translate=).
            'toasts' => $status === 200 ? $this->offers->termById($sections, $request->getQueryParams()['translate'] ?? null, $locale) : [],
        ]), $status);
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

    /**
     * Where a pinned item's name leads: an archive's list in the screen's language; a single's entry form (its entry in
     * that language, else in the default one, whose form offers the translation), or "Add new" before it exists. Null
     * when the user may not go there.
     */
    private function contentUrl(User $user, Collection $collection, string $locale): ?string
    {
        if (!$this->access->canView($user, $collection)) {
            return null;
        }
        if (!$collection->single) {
            return $this->router->url('admin.entries', ['collection' => $collection->name, 'locale' => $locale]);
        }
        $entry = ($collection->translatable ? $this->entries->first($collection->name, $locale) : null)
            ?? $this->entries->first($collection->name, $this->locales->default());
        if ($entry === null) {
            return $this->access->canCreate($user, $collection) ? $this->router->url('admin.entries.new', ['collection' => $collection->name, 'locale' => $locale]) : null;
        }

        // The default language's entry stands in for a missing translation: its form offers to create it.
        $params = ['collection' => $collection->name, 'id' => $entry->id, ...($entry->locale !== $locale && $collection->translatable ? ['translate' => $locale] : [])];

        return $this->access->canEdit($user, $entry) ? $this->router->url('admin.entries.edit', $params) : null;
    }
}
