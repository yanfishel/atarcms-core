<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\TreeForm;
use AtarCms\Admin\TreeResponder;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\User;
use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\PublicContent;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The order screen of a hierarchical collection: every entry of one locale in the tree editor.
 * Needs "view" and "edit" on the collection; every entry that moves must be editable by the user.
 */
final class EntryOrderController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly EntryRequest $entryRequest,
        private readonly EntryAccess $access,
        private readonly EntryRepository $entries,
        private readonly EntryService $service,
        private readonly PublicContent $content,
        private readonly TreeResponder $tree,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$collection, $user] = $this->authorize($request);
        $locale = $this->entryRequest->contentLocale($request, $collection);

        return $this->page($request, $collection, $user, $locale, [], [], 200);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        [$collection, $user] = $this->authorize($request);
        $form = TreeForm::parse($request);
        $locale = $this->entryRequest->locale($collection, $form->locale);
        $errors = $form->errors;
        $params = [];
        if ($form->tree !== null) {
            try {
                $this->service->reorder(
                    $collection,
                    $locale,
                    $form->tree,
                    $form->version,
                    fn(Entry $entry): bool => $this->access->canEdit($user, $entry),
                    $user,
                    ClientIp::of($request),
                );

                return $this->tree->saved(
                    $request,
                    fn(): string => TreeOrder::version($this->entries->forTree($collection->name, $locale)),
                    $this->router->url('admin.entries.order', ['collection' => $collection->name, 'locale' => $locale]),
                );
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->tree->failed($request, $errors, $params, fn(): ResponseInterface => $this->page($request, $collection, $user, $locale, $errors, $params, 422));
    }

    /**
     * @return array{0: Collection, 1: User}
     */
    private function authorize(ServerRequestInterface $request): array
    {
        $collection = $this->entryRequest->collection($request);
        if (!$collection->hierarchical || $collection->single) {
            throw new NotFound(sprintf('Collection "%s" has no tree', $collection->name));
        }
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $this->access->authorize($this->access->canReorder($user, $collection), 'reorder ' . $collection->name);

        return [$collection, $user];
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function page(ServerRequestInterface $request, Collection $collection, User $user, string $locale, array $errors, array $params, int $status): ResponseInterface
    {
        $entries = $this->entries->forTree($collection->name, $locale);
        $items = [];
        foreach ($entries as $entry) {
            $items[$entry->id] = [
                'label' => $entry->title,
                'url' => $this->access->canEdit($user, $entry)
                    ? $this->router->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $entry->id])
                    : null,
                'meta' => $entry->slug,
                'hidden' => !$this->content->isPublic($entry),
            ];
        }

        return $this->responses->html($this->view->render('entries/order', $request, [
            'collection' => $collection,
            'locale' => $locale,
            'tree' => TreeOrder::nest($entries),
            'items' => $items,
            'version' => TreeOrder::version($entries),
            'maxDepth' => TreeOrder::MAX_DEPTH,
            'errors' => $errors,
            'params' => $params,
        ]), $status);
    }
}
