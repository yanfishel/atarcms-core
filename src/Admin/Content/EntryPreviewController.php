<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryService;
use AtarCms\Content\Http\ContentController;
use AtarCms\Content\PageSeo;
use AtarCms\Content\TermTree;
use AtarCms\Content\TermUrls;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\I18n\Alternates;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\SiteTime;
use AtarCms\View\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The entry form's Preview: the posted, unsaved form rendered by the site theme like the entry's public page
 * (ContentController's templates), in the entry's language. Nothing is saved (EntryService::preview()); invalid values
 * are left out instead of shown as errors. The page is never cached or indexed and has no canonical URL, language
 * alternates or social tags; it needs the right to save the form (create a new entry, or edit this one).
 */
final class EntryPreviewController
{
    public function __construct(
        private readonly EntryRequest $entryRequest,
        private readonly EntryAccess $access,
        private readonly EntryService $service,
        private readonly TermUrls $termUrls,
        private readonly Renderer $renderer,
        private readonly Responses $responses,
        private readonly Locales $locales,
        private readonly SiteTime $time,
    ) {}

    public function previewNew(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $this->access->authorize($this->access->canCreate($user, $collection), 'create ' . $collection->name);
        $data = EntryForm::parse($request, $collection, $this->time);

        return $this->render($request, $collection, null, $this->entryRequest->locale($collection, $data->locale), $data);
    }

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $entry = $this->entryRequest->entry($request, $collection);
        $this->access->authorize($this->access->canEdit($user, $entry), 'edit entry #' . $entry->id);

        return $this->render($request, $collection, $entry, $entry->locale, EntryForm::parse($request, $collection, $this->time, $entry));
    }

    private function collection(ServerRequestInterface $request): Collection
    {
        $collection = $this->entryRequest->collection($request);
        if ($collection->route === null) {
            throw new NotFound(sprintf('The %s collection has no public page to preview', $collection->name));
        }

        return $collection;
    }

    private function render(ServerRequestInterface $request, Collection $collection, ?Entry $existing, string $locale, EntryFormData $data): ResponseInterface
    {
        $preview = $this->service->preview($collection, $locale, $data->input, $existing, CurrentUser::require($request));
        $routeTerm = $preview->routeTerm();
        $sections = $routeTerm === null ? [] : TermTree::trail(array_values($this->termUrls->ancestors([$routeTerm])), $routeTerm);
        $request = $preview->attach($request)
            ->withAttribute(RequestLocale::class, new RequestLocale($locale, $locale !== $this->locales->default()))
            ->withAttribute(Alternates::class, new Alternates([]));

        return $this->responses->html($this->renderer->render(
            ContentController::templates($collection, 'show'),
            ['collection' => $collection, 'entry' => $preview->entry, 'seo' => PageSeo::forEntry($preview->entry, $sections)],
            $request,
        ))->withHeader('Cache-Control', 'no-store, private')->withHeader('X-Robots-Tag', 'noindex');
    }
}
