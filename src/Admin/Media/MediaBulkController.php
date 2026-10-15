<?php

declare(strict_types=1);

namespace AtarCms\Admin\Media;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Flash;
use AtarCms\Auth\CurrentUser;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaService;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The library's bulk actions on the checked files (ids[]): move them to a folder ("media.upload") and delete them
 * after a confirmation page ("media.delete"); used files are kept and counted. Both return to the library as it was
 * (back[…]) with toasts.
 */
final class MediaBulkController
{
    /** The library parameters a bulk form carries back (not the page: a move or delete may empty the last one). */
    private const array BACK = ['folder', 'type', 'sort', 'q', 'everywhere', 'unused'];

    private const int MAX = 500;

    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly MediaRepository $media,
        private readonly MediaService $service,
        private readonly SiteSettings $settings,
    ) {}

    public function move(ServerRequestInterface $request): ResponseInterface
    {
        $body = self::body($request);
        $session = Session::of($request);
        $ids = self::ids($body);
        if ($ids === []) {
            Flash::error($session, 'validation.media.none_selected');

            return $this->back($body);
        }
        // "" is "All files"; anything else must be a folder id (an unknown id is refused by the service).
        $target = $body['target'] ?? '';
        $folder = MediaFoldersController::folderId($target);
        if ($target !== '' && $folder === null) {
            Flash::error($session, 'validation.folder.gone');

            return $this->back($body);
        }
        try {
            $moved = $this->service->move($ids, $folder, CurrentUser::require($request), ClientIp::of($request));
            Flash::success($session, 'admin.media.flash.moved', ['count' => $moved]);
        } catch (ValidationFailed $e) {
            Flash::error($session, $e->errors['folder'] ?? 'validation.folder.gone');
        }

        return $this->back($body);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $body = self::body($request);
        $session = Session::of($request);
        $ids = self::ids($body);
        $items = array_values($this->media->byIds($ids));
        if ($items === []) {
            // A confirmed delete sent twice finds nothing left: nothing to report.
            if (($body['confirmed'] ?? null) !== '1') {
                Flash::error($session, 'validation.media.none_selected');
            }

            return $this->back($body);
        }
        if (($body['confirmed'] ?? null) !== '1') {
            $usage = $this->media->usageCounts($ids);
            $settingsUses = $this->settings->mediaUses();
            $used = count(array_filter($items, static fn($item): bool => ($usage[$item->id] ?? 0) > 0 || isset($settingsUses[$item->id])));

            return $this->responses->html($this->view->render('media/delete-many', $request, [
                'items' => $items,
                'used' => $used,
                'back' => self::backParams($body),
                'backUrl' => $this->router->url('admin.media', self::backParams($body)),
            ]));
        }
        $user = CurrentUser::require($request);
        $ip = ClientIp::of($request);
        [$deleted, $kept] = [0, 0];
        foreach ($items as $item) {
            try {
                $this->service->delete($item, $user, $ip);
                ++$deleted;
            } catch (ValidationFailed) {
                ++$kept;
            } catch (NotFound) {
                // Deleted in the meantime: nothing to report.
            }
        }
        if ($deleted > 0) {
            Flash::success($session, 'admin.media.flash.deleted_many', ['count' => $deleted]);
        }
        if ($kept > 0) {
            Flash::error($session, 'admin.media.flash.kept', ['count' => $kept]);
        }

        return $this->back($body);
    }

    /**
     * @param array<mixed> $body
     */
    private function back(array $body): ResponseInterface
    {
        return $this->responses->redirect($this->router->url('admin.media', self::backParams($body)), 303);
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<string, string>
     */
    private static function backParams(array $body): array
    {
        $back = is_array($body['back'] ?? null) ? $body['back'] : [];
        $params = [];
        foreach (self::BACK as $key) {
            $value = $back[$key] ?? null;
            if (is_string($value) && $value !== '' && strlen($value) <= 100) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<int>
     */
    private static function ids(array $body): array
    {
        $ids = [];
        foreach (is_array($body['ids'] ?? null) ? $body['ids'] : [] as $id) {
            if (is_string($id) && preg_match('/^[1-9]\d{0,9}$/', $id) === 1) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_slice(array_values($ids), 0, self::MAX);
    }

    /**
     * @return array<mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
