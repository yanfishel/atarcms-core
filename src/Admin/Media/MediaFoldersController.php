<?php

declare(strict_types=1);

namespace AtarCms\Admin\Media;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Dialog;
use AtarCms\Admin\Flash;
use AtarCms\Auth\CurrentUser;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\Media\MediaFolder;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaFolderService;
use AtarCms\Media\MediaFolderTree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The folder pages of the media library: new folder, rename and move (one form), delete with a confirmation page.
 * Creating and editing need "media.upload", deleting "media.delete" (routes). Name and parent errors stay at their
 * fields; a stale form, a gone folder and a refused delete are error toasts.
 */
final class MediaFoldersController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly MediaFolderRepository $folders,
        private readonly MediaFolderService $service,
    ) {}

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $parent = self::folderId($request->getQueryParams()['parent'] ?? null);
        $tree = new MediaFolderTree($this->folders->all());

        return $this->form($request, $tree, null, ['name' => '', 'parent_id' => $tree->find($parent)?->id], [], [], '');
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$name, $parent] = self::submitted($request);
        try {
            $folder = $this->service->create($name, $parent, CurrentUser::require($request), ClientIp::of($request));
        } catch (ValidationFailed $e) {
            return $this->form($request, new MediaFolderTree($this->folders->all()), null, ['name' => is_string($name) ? $name : '', 'parent_id' => $parent], $e->errors, $e->params, '', 422);
        }
        Flash::success(Session::of($request), 'admin.media.folders.flash.created', ['name' => $folder->name]);

        return $this->toLibrary($request, $folder->id);
    }

    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $folder = $this->folder($request);

        return $this->form($request, new MediaFolderTree($this->folders->all()), $folder, ['name' => $folder->name, 'parent_id' => $folder->parentId], [], [], MediaFolderService::version($folder));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $folder = $this->folder($request);
        [$name, $parent] = self::submitted($request);
        $body = $request->getParsedBody();
        $version = is_array($body) && is_string($body['version'] ?? null) ? $body['version'] : '';
        try {
            $saved = $this->service->update($folder, $name, $parent, $version, CurrentUser::require($request), ClientIp::of($request));
        } catch (NotFound) {
            Flash::error(Session::of($request), 'validation.folder.gone');

            return $this->toLibrary($request, null);
        } catch (ValidationFailed $e) {
            // In the dialog a refusal without a field (a stale form) reloads the screen with an error toast.
            if (Dialog::requested($request) && isset($e->errors['folder'])) {
                Flash::error(Session::of($request), $e->errors['folder'], $e->params['folder'] ?? []);

                return $this->toLibrary($request, $folder->id);
            }

            return $this->form($request, new MediaFolderTree($this->folders->all()), $folder, ['name' => is_string($name) ? $name : '', 'parent_id' => $parent], $e->errors, $e->params, $version, 422);
        }
        Flash::success(Session::of($request), 'admin.media.folders.flash.saved');

        return $this->toLibrary($request, $saved->id);
    }

    public function confirmDelete(ServerRequestInterface $request): ResponseInterface
    {
        $folder = $this->folder($request);
        $tree = new MediaFolderTree($this->folders->all());

        return $this->responses->html($this->view->render(Dialog::requested($request) ? 'media/folder-delete-dialog' : 'media/folder-delete', $request, ['folder' => $folder, 'trail' => $tree->trail($folder->id)]));
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $folder = $this->folder($request);
        $session = Session::of($request);
        try {
            $this->service->delete($folder, CurrentUser::require($request), ClientIp::of($request));
        } catch (NotFound) {
            Flash::error($session, 'validation.folder.gone');

            return $this->toLibrary($request, null);
        } catch (ValidationFailed $e) {
            Flash::error($session, $e->errors['folder'] ?? 'validation.folder.not_empty', $e->params['folder'] ?? []);
            if (Dialog::requested($request)) {
                return $this->toLibrary($request, $folder->id);
            }

            return $this->responses->redirect($this->router->url('admin.media.folders.edit', ['id' => $folder->id]), 303);
        }
        Flash::success($session, 'admin.media.folders.flash.deleted', ['name' => $folder->name]);

        return $this->toLibrary($request, $folder->parentId);
    }

    /**
     * @param array{name: string, parent_id: int|null} $values
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function form(ServerRequestInterface $request, MediaFolderTree $tree, ?MediaFolder $folder, array $values, array $errors, array $params, string $version, int $status = 200): ResponseInterface
    {
        $dialog = Dialog::requested($request);

        return $this->responses->html($this->view->render($dialog ? 'media/folder-dialog' : 'media/folder-form', $request, [
            'folder' => $folder,
            'values' => $values,
            'errors' => $errors,
            'params' => $params,
            'version' => $version,
            'options' => $tree->options(),
            'excluded' => $folder === null ? [] : $tree->withDescendants($folder->id),
            'trail' => $tree->trail($folder === null ? $values['parent_id'] : $folder->id),
            'deleteBlocked' => $folder === null ? null : $this->deleteBlocked($folder),
        ]), $dialog ? 200 : $status); // htmx swaps no error response: a refused dialog form comes back as 200
    }

    /**
     * Why the folder cannot be deleted (subfolders or files, as MediaFolderService::delete() would refuse), or null.
     *
     * @return array{key: string, params: array<string, int>}|null
     */
    private function deleteBlocked(MediaFolder $folder): ?array
    {
        $folders = $this->folders->childCount($folder->id);
        $files = $this->folders->fileCount($folder->id);

        return $folders === 0 && $files === 0 ? null : ['key' => 'validation.folder.not_empty', 'params' => ['folders' => $folders, 'files' => $files]];
    }

    /**
     * Back to the library at $folder: a redirect, or for the dialog (htmx) HX-Redirect, so the screen loads again with
     * the flash.
     */
    private function toLibrary(ServerRequestInterface $request, ?int $folder): ResponseInterface
    {
        $url = $this->router->url('admin.media', $folder === null ? [] : ['folder' => $folder]);

        return Dialog::requested($request) ? Dialog::redirect($this->responses, $url) : $this->responses->redirect($url, 303);
    }

    private function folder(ServerRequestInterface $request): MediaFolder
    {
        $id = $request->getAttribute('id');
        $folder = is_string($id) && strlen($id) <= 10 ? $this->folders->find((int) $id) : null;

        return $folder ?? throw new NotFound(sprintf('No media folder #%s', is_string($id) ? $id : '?'));
    }

    /**
     * @return array{0: mixed, 1: int|null} the submitted name (validated by the service) and parent (null: the root)
     */
    private static function submitted(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        return [$body['name'] ?? '', self::folderId($body['parent_id'] ?? null)];
    }

    /**
     * A folder id from a form or query value; anything else (empty included) is null, the root.
     */
    public static function folderId(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) === 1 ? (int) $value : null;
    }
}
