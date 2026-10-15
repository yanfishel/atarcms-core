<?php

declare(strict_types=1);

namespace AtarCms\Admin\Media;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Flash;
use AtarCms\Auth\CurrentUser;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\Media;
use AtarCms\Media\MediaConfig;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaFolderTree;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaUrls;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The media library and the picker fragment of the entry form: folders, grid or list view, type filter, sort,
 * search (the current folder and its subfolders, or everywhere), unused files (library), pages, and uploads into the
 * current folder. Routes require "media.upload". An upload answers a plain form with a redirect and flash messages,
 * an htmx request with the library (or picker) fragment, and a JSON request (the rich-text editor) with the stored
 * media. The picker's tiles carry each file's address and, for a site locale given as ?locale= (the rich-text
 * editor's content language), its alt text in that language. The views and the picker's folder are remembered in
 * cookies (MediaPreferences).
 *
 * @phpstan-type Failure array{key: string, params: array<string, int|float|string>}
 */
final class MediaController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly MediaRepository $media,
        private readonly MediaFolderRepository $folders,
        private readonly MediaService $service,
        private readonly MediaConfig $config,
        private readonly MediaUrls $urls,
        private readonly Translator $translator,
        private readonly Locales $locales,
        private readonly SiteSettings $settings,
        private readonly Config $site,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $tree = new MediaFolderTree($this->folders->all());
        $folder = $tree->find(MediaFoldersController::folderId($request->getQueryParams()['folder'] ?? null))?->id;
        $list = MediaListQuery::fromQuery($request->getQueryParams(), MediaPreferences::view($request, MediaPreferences::LIBRARY_VIEW), $folder);

        $response = $this->responses->html($this->view->render('media/index', $request, [
            'library' => $this->library($tree, $list, [], []),
            'tree' => $tree,
            'list' => $list,
            'accept' => $this->config->accept(),
            'help' => $this->uploadHelp($this->config->types),
        ]));

        return MediaPreferences::remember($request, $response, MediaPreferences::LIBRARY_VIEW, $this->site->appUrl());
    }

    public function picker(ServerRequestInterface $request): ResponseInterface
    {
        $tree = new MediaFolderTree($this->folders->all());
        $folder = $tree->find(MediaPreferences::pickerFolder($request))?->id;
        $list = $this->pickerQuery($request, MediaPreferences::view($request, MediaPreferences::PICKER_VIEW), $folder);
        $response = $this->responses->html($this->view->render('media/picker', $request, $this->pickerData($tree, $list, [], [])));

        return MediaPreferences::remember($request, $response, MediaPreferences::PICKER_VIEW, $this->site->appUrl(), true, $list->folder);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $user = CurrentUser::require($request);
        $ip = ClientIp::of($request);
        $body = $request->getParsedBody();
        $folder = MediaFoldersController::folderId(is_array($body) ? ($body['folder'] ?? null) : null);
        $uploaded = [];
        /** @var list<Failure> $failures */
        $failures = [];
        $files = self::files($request);
        if ($files === []) {
            $failures[] = ['key' => 'validation.media.missing', 'params' => []];
        }
        foreach ($files as $file) {
            try {
                $uploaded[] = $this->service->upload($file, $user, $ip, $folder);
            } catch (ValidationFailed $e) {
                $failures[] = ['key' => $e->errors['file'] ?? 'validation.media.failed', 'params' => $e->params['file'] ?? []];
            }
        }

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $locale = RequestLocale::of($request)->code;

            return $this->responses->json([
                'media' => array_map($this->describe(...), $uploaded),
                'errors' => array_map(fn(array $failure): string => $this->translator->translate($locale, $failure['key'], $failure['params']), $failures),
            ], $uploaded === [] ? 422 : 200);
        }
        if ($request->getHeaderLine('HX-Request') === 'true') {
            $tree = new MediaFolderTree($this->folders->all());
            $folder = $tree->find($folder)?->id;
            // Always 200: htmx does not swap error responses, and the fragment shows the failures as toasts. The
            // fragment shows the folder uploaded into, newest first, so the new files are on its first page.
            if (($request->getQueryParams()['context'] ?? null) === 'picker') {
                $list = $this->pickerQuery($request, MediaPreferences::view($request, MediaPreferences::PICKER_VIEW), $folder)->withoutSearch();

                return $this->responses->html($this->view->render('media/picker', $request, $this->pickerData($tree, $list, $uploaded, $failures)));
            }
            // The toolbar's fields come along (hx-include), so the results keep its view, filters and order.
            $fields = [...$request->getQueryParams(), ...(is_array($body) ? $body : [])];
            $view = $fields['view'] ?? null;
            $list = MediaListQuery::fromQuery($fields, is_string($view) && in_array($view, MediaListQuery::VIEWS, true) ? $view : MediaPreferences::view($request, MediaPreferences::LIBRARY_VIEW), $folder);

            return $this->responses->html($this->view->render('media/library', $request, $this->library($tree, $list, $uploaded, $failures)));
        }
        $session = Session::of($request);
        if ($uploaded !== []) {
            Flash::success($session, 'admin.media.flash.uploaded', ['count' => count($uploaded)]);
        }
        foreach ($failures as $failure) {
            Flash::error($session, $failure['key'], $failure['params']);
        }

        return $this->responses->redirect($this->router->url('admin.media', $folder === null ? [] : ['folder' => $folder]), 303);
    }

    /**
     * @param list<Media> $uploaded
     * @param list<Failure> $failures
     *
     * @return array<string, mixed> the data of media/library
     */
    private function library(MediaFolderTree $tree, MediaListQuery $list, array $uploaded, array $failures): array
    {
        $alsoUsed = $this->alsoUsed();
        $page = $this->media->page($list->toMediaQuery($tree, $alsoUsed));
        $usage = [];
        if ($list->view === 'list') {
            $usage = $this->media->usageCounts(array_map(static fn(Media $media): int => $media->id, $page->items));
            // The site settings count as one use of each file they use (logo, icon, sharing image).
            foreach ($alsoUsed as $id) {
                if (isset($usage[$id])) {
                    ++$usage[$id];
                }
            }
        }

        return [
            'tree' => $tree,
            'list' => $list,
            'page' => $page,
            'usage' => $usage,
            'uploaded' => count($uploaded),
            'failures' => $failures,
            'labels' => MediaConfig::LABELS,
        ];
    }

    /**
     * @return list<int> files used outside the entries (the site settings' logo, icon and sharing image), for the
     *                   "Unused" filter
     */
    private function alsoUsed(): array
    {
        return array_keys($this->settings->mediaUses());
    }

    private function pickerQuery(ServerRequestInterface $request, string $view, ?int $folder): MediaListQuery
    {
        $list = MediaListQuery::fromQuery($request->getQueryParams(), $view, $folder);

        return $list->locale === '' || $this->locales->isSupported($list->locale) ? $list : $list->withoutLocale();
    }

    /**
     * @param list<Media> $uploaded
     * @param list<Failure> $failures
     *
     * @return array<string, mixed> the data of media/picker
     */
    private function pickerData(MediaFolderTree $tree, MediaListQuery $list, array $uploaded, array $failures): array
    {
        $types = match ($list->kind) {
            'image' => $this->config->imageTypes(),
            'video' => $this->config->videoTypes(),
            default => $this->config->types,
        };
        // Files of other kinds uploaded here are stored, but not offered (the picker of a video lists videos only).
        $offered = static fn(Media $media): bool => match ($list->kind) {
            'image' => $media->isImage(),
            'video' => $media->isVideo(),
            default => true,
        };

        return [
            'tree' => $tree,
            'list' => $list,
            'page' => $this->media->page($list->toMediaQuery($tree, $this->alsoUsed())),
            'uploaded' => count($uploaded),
            'uploadedIds' => array_map(static fn(Media $media): int => $media->id, array_values(array_filter($uploaded, $offered))),
            'failures' => $failures,
            'labels' => MediaConfig::LABELS,
            'accept' => $this->config->accept($types),
            'help' => $this->uploadHelp($types),
        ];
    }

    /**
     * The upload help line: the types and the size limit, with the videos' own limit when videos are among others.
     *
     * @param list<string> $types the types this upload form takes
     *
     * @return array{key: string, params: array<string, float|string>}
     */
    private function uploadHelp(array $types): array
    {
        $params = ['types' => $this->config->labels($types), 'max' => $this->config->maxSizeMegabytes()];
        $videos = array_filter($types, $this->config->isVideo(...));
        if ($videos === []) {
            return ['key' => 'admin.media.upload.help', 'params' => $params];
        }
        if (count($videos) === count($types)) {
            return ['key' => 'admin.media.upload.help', 'params' => [...$params, 'max' => $this->config->maxSizeMegabytes('video/mp4')]];
        }

        return ['key' => 'admin.media.upload.help_video', 'params' => [...$params, 'video' => $this->config->maxSizeMegabytes('video/mp4')]];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Media $media): array
    {
        return [
            'id' => $media->id,
            'name' => $media->originalName,
            'mime' => $media->mime,
            'url' => $this->urls->original($media),
            'thumb' => $media->isImage() ? $this->urls->variant($media, ImageVariants::ADMIN, true) : null,
            'width' => $media->width,
            'height' => $media->height,
        ];
    }

    /**
     * @return list<UploadedFileInterface> the submitted files[] (a single "files" too), without empty file inputs
     */
    private static function files(ServerRequestInterface $request): array
    {
        $submitted = $request->getUploadedFiles()['files'] ?? [];
        $files = [];
        foreach ($submitted instanceof UploadedFileInterface ? [$submitted] : (is_array($submitted) ? $submitted : []) as $file) {
            if ($file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
