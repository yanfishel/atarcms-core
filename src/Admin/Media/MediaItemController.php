<?php

declare(strict_types=1);

namespace AtarCms\Admin\Media;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Flash;
use AtarCms\Auth\Authorizer;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\UserRepository;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryRepository;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Config;
use AtarCms\Media\Media;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaFolderTree;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaUrls;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One medium: details, alternative texts per locale ("media.upload") and deletion with a confirmation page
 * ("media.delete"). Both pages list the entries that use the file; a used file cannot be deleted.
 *
 * @phpstan-type Usage array{title: string|null, collection: string|null, locale: string|null, field: string, url: string|null}
 */
final class MediaItemController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly MediaRequest $mediaRequest,
        private readonly MediaService $service,
        private readonly MediaRepository $media,
        private readonly EntryRepository $entries,
        private readonly EntryAccess $access,
        private readonly Authorizer $authorizer,
        private readonly Locales $locales,
        private readonly UserRepository $users,
        private readonly SiteSettings $settings,
        private readonly Config $config,
        private readonly MediaUrls $urls,
        private readonly MediaFolderRepository $folders,
    ) {}

    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $media = $this->mediaRequest->media($request);

        return $this->form($request, $media, $media->alt, [], [], MediaService::version($media));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $media = $this->mediaRequest->media($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $submitted = is_array($body['alt'] ?? null) ? $body['alt'] : [];
        $version = is_string($body['version'] ?? null) ? $body['version'] : '';
        try {
            $this->service->updateAlt($media, $submitted, $version, CurrentUser::require($request), ClientIp::of($request));
            Flash::success(Session::of($request), 'admin.media.flash.saved');

            return $this->responses->redirect($this->router->url('admin.media.edit', ['id' => $media->id]), 303);
        } catch (ValidationFailed $e) {
            $alt = [];
            foreach ($submitted as $locale => $text) {
                if (is_string($locale) && is_string($text)) {
                    $alt[$locale] = $text;
                }
            }

            return $this->form($request, $media, $alt, $e->errors, $e->params, $version, 422);
        }
    }

    public function confirmDelete(ServerRequestInterface $request): ResponseInterface
    {
        $media = $this->mediaRequest->media($request);

        return $this->deletePage($request, $media);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $media = $this->mediaRequest->media($request);
        try {
            $this->service->delete($media, CurrentUser::require($request), ClientIp::of($request));
        } catch (ValidationFailed $e) {
            // An error toast where the confirmation's Cancel leads: the file's page, whose "Used in" list shows what keeps it.
            Flash::refusal(Session::of($request), $e);

            return $this->responses->redirect($this->cancelUrl($request, $media), 303);
        }
        Flash::success(Session::of($request), 'admin.media.flash.deleted');

        return $this->responses->redirect($this->router->url($this->mayUpload($request) ? 'admin.media' : 'admin.dashboard'), 303);
    }

    /**
     * @param array<string, string> $alt
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function form(ServerRequestInterface $request, Media $media, array $alt, array $errors, array $params, string $version, int $status = 200): ResponseInterface
    {
        return $this->responses->html($this->view->render('media/edit', $request, [
            'media' => $media,
            'alt' => $alt,
            'locales' => $this->locales->all(),
            'errors' => $errors,
            'params' => $params,
            'version' => $version,
            'usages' => $this->usages($request, $media),
            'settingsUse' => $this->settingsUse($request, $media),
            'canDelete' => $this->authorizer->can(CurrentUser::of($request), MediaService::DELETE),
            'uploadedBy' => $media->uploadedBy === null ? null : ($this->users->displayNames([$media->uploadedBy])[$media->uploadedBy] ?? null),
            'address' => $this->config->appUrl() . $this->urls->original($media),
            'trail' => new MediaFolderTree($this->folders->all())->trail($media->folderId),
        ]), $status);
    }

    /**
     * The site settings' uses of the file (logo, icon, sharing image): null when they use it not; the settings
     * screen's address for users who may open it.
     *
     * @return array{url: string|null, uses: list<string>}|null
     */
    private function settingsUse(ServerRequestInterface $request, Media $media): ?array
    {
        $uses = $this->settings->mediaUses()[$media->id] ?? [];
        if ($uses === []) {
            return null;
        }

        return [
            'url' => $this->authorizer->can(CurrentUser::of($request), SettingsService::PERMISSION) ? $this->router->url('admin.settings') : null,
            'uses' => $uses,
        ];
    }

    private function deletePage(ServerRequestInterface $request, Media $media): ResponseInterface
    {
        return $this->responses->html($this->view->render('media/delete', $request, [
            'media' => $media,
            'usages' => $this->usages($request, $media),
            'settingsUse' => $this->settingsUse($request, $media),
            'back' => $this->cancelUrl($request, $media),
            'canEdit' => $this->mayUpload($request),
        ]));
    }

    /**
     * Where the delete confirmation's Cancel (and a refused delete) leads: the file's page, which needs "media.upload"
     * like the library; without it (deleting needs only "media.delete") the dashboard.
     */
    private function cancelUrl(ServerRequestInterface $request, Media $media): string
    {
        return $this->mayUpload($request) ? $this->router->url('admin.media.edit', ['id' => $media->id]) : $this->router->url('admin.dashboard');
    }

    private function mayUpload(ServerRequestInterface $request): bool
    {
        return $this->authorizer->can(CurrentUser::of($request), MediaService::UPLOAD);
    }

    /**
     * The entries using the file, with titles and links only for collections the user can view.
     *
     * @return list<Usage>
     */
    private function usages(ServerRequestInterface $request, Media $media): array
    {
        $user = CurrentUser::of($request);
        $rows = $this->media->usages($media->id);
        $entries = $this->entries->byIds(array_values(array_unique(array_map(static fn(array $row): int => $row['entryId'], $rows))));
        $usages = [];
        foreach ($rows as $row) {
            $entry = $entries[$row['entryId']] ?? null;
            $field = $row['field'];
            if ($entry !== null) {
                $definition = $entry->collection->field($field);
                $field = $definition === null ? $field : $definition->label;
            }
            if ($entry === null || !$this->access->canView($user, $entry->collection)) {
                $usages[] = ['title' => null, 'collection' => null, 'locale' => null, 'field' => $field, 'url' => null];

                continue;
            }
            $usages[] = [
                'title' => $entry->title,
                'collection' => $entry->collection->label,
                'locale' => $entry->locale,
                'field' => $field,
                'url' => $this->router->url('admin.entries.edit', ['collection' => $entry->collection->name, 'id' => $entry->id]),
            ];
        }

        return $usages;
    }
}
