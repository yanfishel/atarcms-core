<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Flash;
use AtarCms\Admin\MachineTranslation;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\UserRepository;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryService;
use AtarCms\Content\Revision;
use AtarCms\Content\RevisionRepository;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Entry actions besides editing: copy for translation (and machine translation, Plan 38), delete (with a confirmation page), revisions and restore.
 */
final class EntryActionsController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly EntryRequest $entryRequest,
        private readonly EntryAccess $access,
        private readonly EntryService $service,
        private readonly RevisionRepository $revisions,
        private readonly UserRepository $users,
    ) {}

    public function translate(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $entry = $this->entryRequest->entry($request, $collection);
        $this->access->authorize(
            $this->access->canTranslate($user, $entry),
            'translate entry #' . $entry->id,
        );
        $body = $request->getParsedBody();
        $locale = is_array($body) && is_string($body['locale'] ?? null) ? $body['locale'] : '';

        try {
            $translation = $this->service->createTranslation($entry, $locale, $user, ClientIp::of($request));
        } catch (ValidationFailed $e) {
            Flash::refusal(Session::of($request), $e);

            return $this->responses->redirect($this->editUrl($entry), 303);
        }
        Flash::success(Session::of($request), 'admin.entries.flash.translated');

        // The machine button: the copy's form translates it in the browser from the source language.
        $machine = MachineTranslation::chosen($request) ? MachineTranslation::query($entry->locale) : [];

        return $this->responses->redirect($this->router->url('admin.entries.edit', ['collection' => $translation->collection->name, 'id' => $translation->id, 'locale' => $translation->locale, ...$machine]), 303);
    }

    public function confirmDelete(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $entry = $this->entryRequest->entry($request, $collection);
        $this->access->authorize($this->access->canDelete($user, $entry), 'delete entry #' . $entry->id);

        return $this->responses->html($this->view->render('entries/delete', $request, [
            'collection' => $collection,
            'entry' => $entry,
            'canEdit' => $this->access->canEdit($user, $entry),
        ]));
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $entry = $this->entryRequest->entry($request, $collection);
        $this->access->authorize($this->access->canDelete($user, $entry), 'delete entry #' . $entry->id);

        try {
            $this->service->delete($entry, $user, ClientIp::of($request));
        } catch (ValidationFailed $e) {
            // A refusal is an error toast on the page the confirmation cancels to: the entry, else its list.
            Flash::refusal(Session::of($request), $e);

            return $this->responses->redirect($this->cancelUrl($entry, $this->access->canEdit($user, $entry)), 303);
        }
        Flash::success(Session::of($request), 'admin.entries.flash.deleted');

        return $this->responses->redirect($this->router->url('admin.entries', ['collection' => $collection->name, 'locale' => $entry->locale]), 303);
    }

    public function revisions(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $entry = $this->entryRequest->entry($request, $collection);
        $revisions = $this->revisions->forEntry($entry->id);
        $authorIds = array_values(array_unique(array_filter(array_map(static fn(Revision $r): ?int => $r->authorId, $revisions))));

        return $this->responses->html($this->view->render('entries/revisions', $request, [
            'collection' => $collection,
            'entry' => $entry,
            'revisions' => $revisions,
            'authors' => $this->users->displayNames($authorIds),
            'canRestore' => $this->access->canEdit($user, $entry),
        ]));
    }

    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $entry = $this->entryRequest->entry($request, $collection);
        $this->access->authorize($this->access->canEdit($user, $entry), 'restore entry #' . $entry->id);
        $revision = $request->getAttribute('revision');

        try {
            $this->service->restore($entry, is_string($revision) && strlen($revision) <= 10 ? (int) $revision : 0, $user, ClientIp::of($request));
        } catch (ValidationFailed $e) {
            Flash::refusal(Session::of($request), $e);

            return $this->responses->redirect($this->router->url('admin.entries.revisions', ['collection' => $collection->name, 'id' => $entry->id]), 303);
        }
        Flash::success(Session::of($request), 'admin.entries.flash.restored');

        return $this->responses->redirect($this->editUrl($entry), 303);
    }

    /** Where the delete confirmation's Cancel leads: the entry's form when the user may edit it, else its list. */
    private function cancelUrl(Entry $entry, bool $canEdit): string
    {
        return $canEdit ? $this->editUrl($entry) : $this->router->url('admin.entries', ['collection' => $entry->collection->name, 'locale' => $entry->locale]);
    }

    private function editUrl(Entry $entry): string
    {
        return $this->router->url('admin.entries.edit', ['collection' => $entry->collection->name, 'id' => $entry->id]);
    }
}
