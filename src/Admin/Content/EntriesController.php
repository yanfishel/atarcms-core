<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Flash;
use AtarCms\Admin\FlashMessage;
use AtarCms\Admin\LinkedLanguage;
use AtarCms\Admin\MachineTranslation;
use AtarCms\Admin\Settings\SettingsForm;
use AtarCms\Admin\TranslationOffers;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryListing;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\Field\MediaFieldType;
use AtarCms\Content\Field\SlidesType;
use AtarCms\Content\Field\TagsType;
use AtarCms\Content\Field\YouTubeType;
use AtarCms\Content\Field\YouTubeVideo;
use AtarCms\Content\PublicContent;
use AtarCms\Content\ShareImage\ShareImages;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermTree;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Security\CspSources;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\SiteTime;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaUrls;
use AtarCms\Settings\ContactPage;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin entry screens: the list, and the new/edit form.
 *
 * @phpstan-import-type EntryFormValues from EntryFormData
 */
final class EntriesController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly EntryRequest $entryRequest,
        private readonly EntryAccess $access,
        private readonly EntryListing $listing,
        private readonly EntryRepository $entries,
        private readonly UserRepository $users,
        private readonly Locales $locales,
        private readonly EntryService $service,
        private readonly EntryUrls $urls,
        private readonly PublicContent $content,
        private readonly Collections $collections,
        private readonly TermRepository $terms,
        private readonly Taxonomies $taxonomies,
        private readonly MediaRepository $media,
        private readonly SiteTime $time,
        private readonly Clock $clock,
        private readonly Translator $translator,
        private readonly MediaUrls $mediaUrls,
        private readonly TranslationOffers $offers,
        private readonly ContactPage $contactPage,
        private readonly SiteSettings $siteSettings,
        private readonly SettingsService $settingsService,
        private readonly LinkedLanguage $linked,
        private readonly ShareImages $shareImages,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);

        if ($collection->single) {
            // The entry of the content language; without one the default language's, offering the translation.
            $locale = $this->entryRequest->contentLocale($request, $collection);
            $entry = $this->entries->first($collection->name, $locale);
            if ($entry !== null || $locale === $this->locales->default()) {
                return $this->responses->redirect($entry === null
                    ? $this->router->url('admin.entries.new', ['collection' => $collection->name])
                    : $this->router->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $entry->id]));
            }
            $default = $this->entries->first($collection->name, $this->locales->default());

            return $this->responses->redirect($default === null
                ? $this->router->url('admin.entries.new', ['collection' => $collection->name, 'locale' => $locale])
                : $this->router->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $default->id, 'translate' => $locale]));
        }

        $query = $request->getQueryParams();
        $locale = $this->entryRequest->contentLocale($request, $collection);
        $status = EntryStatus::tryFrom(self::stringParam($query, 'status'));
        // Only a term of the collection's taxonomies in the list's locale filters the list; anything else is ignored.
        // A collection routed by its sections ("/{sections}/{slug}") is filtered by its sections only.
        // A term with children stands for its whole branch: the flattened tree lists a term's descendants right after
        // it, deeper than it, so they are read from the options already loaded.
        $termParam = self::stringParam($query, 'term');
        $termId = null;
        $termIds = [];
        $taxonomies = [];
        $termOptions = [];
        foreach ($collection->routeTaxonomy !== null ? [$collection->routeTaxonomy] : $collection->termTaxonomies() as $name) {
            $taxonomies[$name] = $this->taxonomies->get($name);
            $termOptions[$name] = TermTree::flatten($this->terms->forTaxonomy($name, $locale));
            $branchDepth = null;
            foreach ($termOptions[$name] as $node) {
                if ($branchDepth !== null && $node['depth'] <= $branchDepth) {
                    $branchDepth = null;
                }
                if ($branchDepth !== null) {
                    $termIds[] = $node['term']->id;
                } elseif ((string) $node['term']->id === $termParam) {
                    $termId = $node['term']->id;
                    $termIds = [$termId];
                    $branchDepth = $node['depth'];
                }
            }
        }
        $filters = [
            'status' => $status === null ? '' : $status->value,
            'q' => mb_substr(self::stringParam($query, 'q'), 0, 100),
            'sort' => in_array(self::stringParam($query, 'sort'), EntryListing::SORTS, true) ? self::stringParam($query, 'sort') : '',
            'dir' => in_array(self::stringParam($query, 'dir'), ['asc', 'desc'], true) ? self::stringParam($query, 'dir') : '',
            'term' => $termId === null ? '' : (string) $termId,
        ];
        $pageParam = self::stringParam($query, 'page');
        $page = $this->listing->page(
            $collection,
            $locale,
            $status,
            $filters['q'],
            $filters['sort'],
            $filters['dir'],
            preg_match('/^[1-9]\d{0,5}$/', $pageParam) === 1 ? (int) $pageParam : 1,
            $termIds,
        );

        $authorIds = array_values(array_unique(array_filter(array_map(static fn(Entry $e): ?int => $e->authorId, $page->entries))));
        $groups = array_values(array_unique(array_map(static fn(Entry $e): string => $e->translationGroup, $page->entries)));
        $editable = [];
        foreach ($page->entries as $entry) {
            $editable[$entry->id] = $this->access->canEdit($user, $entry);
        }
        // Each row shows the entry's main photo (the share card's), a placeholder without one; a collection without
        // main photo fields (Collection::shareImageFields()) shows neither.
        $showPhotos = $collection->shareImageFields() !== [];

        return $this->responses->html($this->view->render('entries/index', $request, [
            'collection' => $collection,
            'page' => $page,
            'locale' => $locale,
            'filters' => $filters,
            // The order the list is in: the chosen one, else EntryListing's default (its header shows it as well).
            'activeSort' => $filters['sort'] !== '' && $filters['dir'] !== '' ? [$filters['sort'], $filters['dir']] : EntryListing::defaultSort($collection),
            'authors' => $this->users->displayNames($authorIds),
            'groupLocales' => $this->listing->localesByGroup($groups),
            'parents' => $collection->hierarchical ? $this->listing->titles($collection->name, $locale) : [],
            'canCreate' => $this->access->canCreate($user, $collection),
            'canReorder' => $collection->hierarchical && $this->access->canReorder($user, $collection),
            'editable' => $editable,
            'taxonomies' => $taxonomies,
            'termOptions' => $termOptions,
            // The route taxonomy's term of each entry (its section), in one query.
            'routeTerms' => $this->urls->routeTerms($page->entries),
            'showPhotos' => $showPhotos,
            // One media query for the page.
            'photos' => $showPhotos ? $this->shareImages->mainPhotos($page->entries) : [],
            'toasts' => $this->offers->entryById($user, $collection, $query['translate'] ?? null, $locale),
        ]));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $this->access->authorize($this->access->canCreate($user, $collection), 'create ' . $collection->name);
        $locale = $this->entryRequest->contentLocale($request, $collection);
        $existing = $collection->single ? $this->entries->first($collection->name, $locale) : null;
        if ($existing !== null) {
            return $this->responses->redirect($this->editUrl($existing));
        }

        // A single's "Add new" in a language it lacks yet, reached from its form in the linked mode (see edit()), offers
        // to translate the existing entry instead.
        $offers = $this->linked->enabled() ? $this->offers->entryById($user, $collection, $request->getQueryParams()['translate'] ?? null, $locale) : [];

        return $this->form($request, $collection, null, $locale, EntryForm::defaults(), [], [], '', offers: $offers);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $this->access->authorize($this->access->canCreate($user, $collection), 'create ' . $collection->name);
        $data = EntryForm::parse($request, $collection, $this->time);
        $locale = $this->entryRequest->locale($collection, $data->locale);
        $errors = $this->publishErrors($data, $user, $collection);
        $params = [];
        $contact = $this->submittedContacts($request, $collection, $user, $locale);
        if ($errors === [] && ($contact === null || $contact->errors === [])) {
            try {
                $entry = $this->service->create($collection, $locale, $data->input, $user, ClientIp::of($request));
                $this->saveContacts($request, $contact, $locale, $user);
                Flash::success(Session::of($request), 'admin.entries.flash.created');

                return $this->responses->redirect($this->editUrl($entry), 303);
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->form($request, $collection, null, $locale, $data->values, $errors, $params, '', 422, contact: $contact);
    }

    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $entry = $this->entryRequest->entry($request, $collection);
        $this->access->authorize($this->access->canEdit($user, $entry), 'edit entry #' . $entry->id);
        $this->entryRequest->rememberLocale($request, $entry);

        // After the content language switch found no translation of a single (ContentLocaleSwitch: ?translate={locale}).
        $translate = $request->getQueryParams()['translate'] ?? null;
        $offers = $this->offers->entry($user, $entry, $translate);
        // The linked mode speaks one language per screen (Plan 37): the single opens "Add new" in the requested language
        // with the same offer instead; without the right to create, its own form as before.
        if ($offers !== [] && is_string($translate) && $collection->single && $this->linked->enabled() && $this->access->canCreate($user, $collection)) {
            return $this->responses->redirect($this->router->url('admin.entries.new', ['collection' => $collection->name, 'locale' => $translate, 'translate' => (string) $entry->id]));
        }

        // A copy opened for machine translation (Plan 38: ?machine={source}).
        $machine = $collection->translatable ? MachineTranslation::requested($request, $entry->locale, $this->locales)?->forEntry($collection, $entry) : null;

        return $this->form($request, $collection, $entry, $entry->locale, EntryForm::values($entry, $this->time, $this->terms->forEntries([$entry->id])[$entry->id] ?? []), [], [], self::version($entry), offers: $offers, machine: $machine);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $entry = $this->entryRequest->entry($request, $collection);
        $this->access->authorize($this->access->canEdit($user, $entry), 'edit entry #' . $entry->id);
        $data = EntryForm::parse($request, $collection, $this->time, $entry);
        $errors = $data->version !== self::version($entry)
            ? ['entry' => 'validation.entry.stale']
            : $this->publishErrors($data, $user, $collection);
        $params = [];
        $contact = $this->submittedContacts($request, $collection, $user, $entry->locale);
        if ($errors === [] && ($contact === null || $contact->errors === [])) {
            try {
                // A user who cannot publish never sees the published_at field, so a submission from them
                // carries no value for it; keep the entry's stored date instead of wiping it out.
                $input = $this->access->canPublish($user, $collection)
                    ? $data->input
                    : self::withPublishedAt($data->input, $entry->publishedAt);
                $updated = $this->service->update($entry, $input, $user, ClientIp::of($request));
                $this->saveContacts($request, $contact, $entry->locale, $user);
                Flash::success(Session::of($request), 'admin.entries.flash.saved');

                return $this->responses->redirect($this->editUrl($updated), 303);
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->form($request, $collection, $entry, $entry->locale, $data->values, $errors, $params, $data->version, 422, contact: $contact);
    }

    /**
     * The entry form's first autosave of a new entry (autosave.js): creates it as a draft and answers its addresses
     * (see autosaved()). Only a draft is autosaved (409 otherwise); an invalid form is 422 {"invalid": true}, which
     * the script skips quietly. No flash.
     */
    public function autosaveNew(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $this->access->authorize($this->access->canCreate($user, $collection), 'create ' . $collection->name);
        $data = EntryForm::parse($request, $collection, $this->time);
        if ($collection->plain || $data->input->status !== EntryStatus::Draft) {
            return $this->autosaveRefused($request, 'admin.autosave.not_draft');
        }
        if ($data->errors !== []) {
            return $this->responses->json(['invalid' => true], 422);
        }
        try {
            $entry = $this->service->create($collection, $this->entryRequest->locale($collection, $data->locale), $data->input, $user, ClientIp::of($request), autosave: true);
        } catch (ValidationFailed) {
            return $this->responses->json(['invalid' => true], 422);
        }

        return $this->autosaved($entry);
    }

    /**
     * An autosave of a stored draft: the version must match like a manual save (409 with the stale message); a
     * published or scheduled entry, or a posted status other than draft, is 409 too: autosave never changes what is
     * public. Answers like autosaveNew().
     */
    public function autosave(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->entryRequest->collection($request);
        $user = CurrentUser::require($request);
        $this->access->authorize($this->access->canView($user, $collection), 'view ' . $collection->name);
        $entry = $this->entryRequest->entry($request, $collection);
        $this->access->authorize($this->access->canEdit($user, $entry), 'edit entry #' . $entry->id);
        $data = EntryForm::parse($request, $collection, $this->time, $entry);
        if ($collection->plain || $entry->status !== EntryStatus::Draft || $data->input->status !== EntryStatus::Draft) {
            return $this->autosaveRefused($request, 'admin.autosave.not_draft');
        }
        if ($data->version !== self::version($entry)) {
            return $this->autosaveRefused($request, 'admin.autosave.stale');
        }
        if ($data->errors !== []) {
            return $this->responses->json(['invalid' => true], 422);
        }
        try {
            $input = $this->access->canPublish($user, $collection)
                ? $data->input
                : self::withPublishedAt($data->input, $entry->publishedAt);
            $updated = $this->service->update($entry, $input, $user, ClientIp::of($request), autosave: true);
        } catch (ValidationFailed) {
            return $this->responses->json(['invalid' => true], 422);
        }

        return $this->autosaved($updated);
    }

    /**
     * What the form needs to keep editing $entry: its new version and its addresses (a new entry's form switches to
     * them); "preview" is null for a collection without a public page.
     */
    private function autosaved(Entry $entry): ResponseInterface
    {
        $params = ['collection' => $entry->collection->name, 'id' => $entry->id];

        return $this->responses->json([
            'id' => $entry->id,
            'version' => self::version($entry),
            'action' => $this->router->url('admin.entries.update', $params),
            'edit' => $this->editUrl($entry),
            'autosave' => $this->router->url('admin.entries.autosave', $params),
            'preview' => $entry->collection->route === null ? null : $this->router->url('admin.entries.preview', $params),
            'previewTarget' => 'atar-preview-' . $entry->collection->name . '-' . $entry->id,
        ]);
    }

    private function autosaveRefused(ServerRequestInterface $request, string $key): ResponseInterface
    {
        return $this->responses->json(['error' => $this->translator->translate(RequestLocale::of($request)->code, $key)], 409);
    }

    /**
     * Parse errors, plus a non-draft status posted by a user who cannot publish.
     *
     * @return array<string, string>
     */
    private function publishErrors(EntryFormData $data, User $user, Collection $collection): array
    {
        $errors = $data->errors;
        if (!isset($errors['status']) && $data->input->status !== EntryStatus::Draft && !$this->access->canPublish($user, $collection)) {
            $errors['status'] = 'validation.status.not_allowed';
        }

        return $errors;
    }

    /**
     * The contact details submitted with the contact page's form (config content.contact_page), checked; null on other
     * forms and for a user who may not change them (EntryAccess::canEditPublished(); their inputs are not shown). The
     * entry is saved only when they pass; they are written after it (Connection has no nested transactions).
     */
    private function submittedContacts(ServerRequestInterface $request, Collection $collection, User $user, string $locale): ?ContactDetails
    {
        if (!$this->contactPage->is($collection) || !$this->access->canEditPublished($user, $collection)) {
            return null;
        }
        $body = $request->getParsedBody();
        $values = SettingsForm::submitted(is_array($body) ? $body : [], true);
        try {
            $this->settingsService->checkContacts($locale, SettingsForm::contactInput($values));
        } catch (ValidationFailed $e) {
            return new ContactDetails($values, $e->errors, $e->params);
        }

        return new ContactDetails($values);
    }

    /**
     * Writes the checked contact details once the entry is saved (audited as settings.updated with the changed names).
     */
    private function saveContacts(ServerRequestInterface $request, ?ContactDetails $contact, string $locale, User $user): void
    {
        if ($contact !== null) {
            $this->settingsService->saveContacts($locale, SettingsForm::contactInput($contact->values), $user, ClientIp::of($request));
        }
    }

    /**
     * @param EntryFormValues $values
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     * @param list<FlashMessage> $offers the offer to create a translation (TranslationOffers)
     * @param ContactDetails|null $contact the submitted contact details (a refused save), else the stored ones are shown
     * @param MachineTranslation|null $machine the machine translation panel of a copy (Plan 38)
     */
    private function form(
        ServerRequestInterface $request,
        Collection $collection,
        ?Entry $entry,
        string $locale,
        array $values,
        array $errors,
        array $params,
        string $version,
        int $status = 200,
        array $offers = [],
        ?ContactDetails $contact = null,
        ?MachineTranslation $machine = null,
    ): ResponseInterface {
        $user = CurrentUser::require($request);
        $relationOptions = [];
        $relationKept = [];
        foreach ($collection->fields as $field) {
            $target = $field->target === null ? null : $this->collections->find($field->target);
            if ($target === null) {
                continue;
            }
            $options = $this->access->canView($user, $target)
                ? $this->listing->titles($target->name, $this->entryRequest->locale($target, $locale))
                : [];
            $relationOptions[$field->name] = $options;
            // Chosen entries that are not listed (a collection the user cannot view, or past the picker's limit)
            // travel as hidden inputs, so saving the form does not drop them; ids that do not exist are not kept.
            $chosen = self::ids($values['fields'][$field->name] ?? null);
            $relationKept[$field->name] = array_values(array_diff($this->entries->existingIds($target->name, $chosen), array_keys($options)));
        }
        $parentOptions = [];
        $parentUnpublished = false;
        if ($collection->hierarchical) {
            $parentOptions = $this->listing->titles($collection->name, $locale);
            if ($entry !== null) {
                unset($parentOptions[$entry->id]);
            }
            $parent = $entry !== null && $entry->parentId !== null ? $this->entries->find($entry->parentId) : null;
            if ($parent !== null && !isset($parentOptions[$parent->id])) {
                // Past the picker's limit: keep the current parent selectable, so saving does not detach the page.
                $parentOptions[$parent->id] = $parent->title;
            }
            $parentUnpublished = $parent !== null && !$this->content->isPublic($parent);
        }
        $translations = [];
        $translationsEditable = [];
        foreach ($entry !== null && $collection->translatable ? $this->entries->translations($entry->translationGroup) : [] as $translation) {
            $translations[$translation->locale] = $translation;
            $translationsEditable[$translation->locale] = $this->access->canEdit($user, $translation);
        }
        $taxonomies = [];
        $termOptions = [];
        foreach ($collection->taxonomies as $name) {
            $taxonomies[$name] = $this->taxonomies->get($name);
            $termOptions[$name] = TermTree::flatten($this->terms->forTaxonomy($name, $locale));
        }
        // A tags field suggests the existing tags of its taxonomy in the entry's language, by name (the tag box).
        $tagOptions = [];
        foreach ($collection->fields as $name => $field) {
            if ($field->type instanceof TagsType && $field->taxonomy !== null) {
                $tagOptions[$name] = array_map(static fn(Term $term): string => $term->name, $this->terms->forTaxonomy($field->taxonomy, $locale));
            }
        }
        // A YouTube field's card shows the video's cover from YouTube's image host: only this form's policy allows it.
        foreach ($collection->fields as $field) {
            if ($field->type instanceof YouTubeType) {
                CspSources::of($request)?->allow('img-src', YouTubeVideo::THUMBNAIL_HOST);
                break;
            }
        }
        // The chosen media of every media widget, in one query.
        $chosenIds = [];
        foreach ($collection->fields as $name => $field) {
            if ($field->type instanceof SlidesType) {
                $rows = SlidesType::rows($values['fields'][$name] ?? null);
                $chosenIds[$name] = self::ids([...array_column($rows, 'image'), ...array_column($rows, 'video')]);
            } elseif ($field->type instanceof MediaFieldType) {
                $chosenIds[$name] = self::ids($values['fields'][$name] ?? null);
            }
        }
        $chosen = $this->media->byIds(array_values(array_unique(array_merge(...array_values($chosenIds)))));
        $mediaOptions = [];
        foreach ($chosenIds as $name => $ids) {
            $mediaOptions[$name] = array_intersect_key($chosen, array_flip($ids));
        }

        return $this->responses->html($this->view->render('entries/form', $request, [
            'collection' => $collection,
            'entry' => $entry,
            'locale' => $locale,
            'locales' => $collection->translatable ? $this->locales->all() : [$this->locales->default()],
            'translations' => $translations,
            'translationsEditable' => $translationsEditable,
            'canTranslate' => $entry !== null && $this->access->canTranslate($user, $entry),
            'offers' => $offers,
            'machine' => $machine,
            'values' => $values,
            'now' => EntryForm::now($this->clock, $this->time),
            'errors' => $errors,
            'params' => $params,
            'version' => $version,
            'canPublish' => $this->access->canPublish($user, $collection),
            'canCreate' => $this->access->canCreate($user, $collection),
            'canDelete' => $entry !== null && $this->access->canDelete($user, $entry),
            'relationOptions' => $relationOptions,
            'relationKept' => $relationKept,
            'parentOptions' => $parentOptions,
            'parentUnpublished' => $parentUnpublished,
            'taxonomies' => $taxonomies,
            'termOptions' => $termOptions,
            'tagOptions' => $tagOptions,
            'mediaOptions' => $mediaOptions,
            // The SEO tab shows only the generated share image of the saved entry (a user decision), else nothing.
            'shareImage' => $collection->route !== null && !$collection->plain && $entry?->shareImage !== null ? $this->mediaUrls->share($entry->shareImage) : null,
            // The contact page's form has the contact details and the site's map in its side column (ContactMapController
            // changes the map), for who may change them.
            'contactPanel' => $this->contactPage->is($collection) && $this->access->canEditPublished($user, $collection) ? [
                'contact' => $contact ?? new ContactDetails(array_intersect_key(SettingsForm::values($this->siteSettings->of($locale)), SettingsForm::contactFields())),
                'map' => $this->siteSettings->of('')->map,
            ] : null,
        ]), $status);
    }

    private function editUrl(Entry $entry): string
    {
        return $this->router->url('admin.entries.edit', ['collection' => $entry->collection->name, 'id' => $entry->id]);
    }

    private static function version(Entry $entry): string
    {
        return $entry->updatedAt->format('Y-m-d H:i:s');
    }

    private static function withPublishedAt(EntryInput $input, ?DateTimeImmutable $publishedAt): EntryInput
    {
        return new EntryInput(
            title: $input->title,
            slug: $input->slug,
            status: $input->status,
            publishedAt: $publishedAt,
            parentId: $input->parentId,
            position: $input->position,
            fields: $input->fields,
            metaTitle: $input->metaTitle,
            metaDescription: $input->metaDescription,
            terms: $input->terms,
        );
    }

    /**
     * Positive ids from a relation or media value: an int, a list or a comma-separated string, as stored or as submitted.
     *
     * RelationType::normalize() parses the same ids but throws on anything malformed; this reads leniently
     * (skipping invalid entries instead) since it only decides which chosen ids to keep as hidden inputs,
     * not whether the submission is valid.
     *
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $items = is_int($value) ? [$value] : (is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []));
        $ids = [];
        foreach ($items as $item) {
            if (is_int($item) && $item > 0) {
                $ids[] = $item;
            } elseif (is_string($item) && preg_match('/^[1-9]\d{0,9}$/', trim($item)) === 1) {
                $ids[] = (int) trim($item);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<mixed> $query
     */
    private static function stringParam(array $query, string $key): string
    {
        $value = $query[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
