<?php

declare(strict_types=1);

namespace AtarCms\Admin\Taxonomy;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Dialog;
use AtarCms\Admin\Flash;
use AtarCms\Admin\FormErrors;
use AtarCms\Admin\MachineTranslation;
use AtarCms\Admin\TranslationOffers;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Collections;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermService;
use AtarCms\Content\TermTree;
use AtarCms\Content\TermUrls;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\Locales;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The terms of one taxonomy: the list per locale, and the new/edit form (also of the sections, under /admin/sections;
 * TermRoutes; a new term's form takes "?parent="). Requested for the dialog (Dialog, the Sections screen), the form is
 * the dialog's fragment and a saved term sends the browser back to the list in its language. The sections' form has
 * "Main | SEO" tabs (terms/tabs.php) with the texts for search engines. Routes require "taxonomies.manage".
 *
 * @phpstan-import-type TermFormValues from TermFormData
 */
final class TermsController
{
    /** The longest name search of a flat taxonomy's list (the input's maxlength). */
    public const int MAX_SEARCH = 100;

    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly TermRequest $termRequest,
        private readonly TermRepository $terms,
        private readonly TermService $service,
        private readonly TermUrls $urls,
        private readonly Locales $locales,
        private readonly TranslationOffers $offers,
        private readonly Collections $collections,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->termRequest->taxonomy($request);
        $locale = $this->termRequest->contentLocale($request);
        // A flat taxonomy's list searches its names (?q=); a tree keeps every term, so its levels stay whole.
        $query = $request->getQueryParams()['q'] ?? '';
        $query = $taxonomy->hierarchical || !is_string($query) ? '' : mb_substr(trim($query), 0, self::MAX_SEARCH);
        $nodes = TermTree::flatten($query === '' ? $this->terms->forTaxonomy($taxonomy->name, $locale) : $this->terms->search($taxonomy->name, $locale, $query));
        $ids = [];
        $groups = [];
        foreach ($nodes as $node) {
            $ids[] = $node['term']->id;
            $groups[$node['term']->translationGroup] = $node['term']->translationGroup;
        }

        return $this->responses->html($this->view->render('terms/index', $request, [
            'taxonomy' => $taxonomy,
            'locale' => $locale,
            'locales' => $this->locales->all(),
            'nodes' => $nodes,
            'counts' => $this->terms->entryCounts($ids),
            // Tags come from the entries' tags input: no "Add new" when nothing else picks this taxonomy's terms.
            'canAdd' => !$this->collections->onlyTagged($taxonomy->name),
            'query' => $query,
            'groupLocales' => $this->terms->localesByGroup(array_values($groups)),
            // After the content language switch found no translation of the term being edited (?translate=).
            'toasts' => $this->offers->termById($taxonomy, $request->getQueryParams()['translate'] ?? null, $locale),
        ]));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->termRequest->taxonomy($request);
        $locale = $this->termRequest->contentLocale($request);
        $values = TermForm::defaults();
        // "?parent=" (the Sections screen's "Add subsection") preselects a term of this taxonomy and language as the
        // parent; anything else is ignored.
        $parent = $request->getQueryParams()['parent'] ?? null;
        if ($taxonomy->hierarchical && is_string($parent) && ctype_digit($parent)) {
            $term = $this->terms->find((int) $parent);
            if ($term !== null && $term->taxonomy === $taxonomy->name && $term->locale === $locale) {
                $values['parent_id'] = (string) $term->id;
            }
        }

        return $this->form($request, $taxonomy, null, $locale, $values, [], [], '');
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->termRequest->taxonomy($request);
        $data = TermForm::parse($request);
        $locale = $this->termRequest->locale($data->locale);
        $errors = $data->errors;
        $params = [];
        if ($errors === []) {
            try {
                $term = $this->service->create($taxonomy, $locale, $data->input, CurrentUser::require($request), ClientIp::of($request));
                Flash::success(Session::of($request), 'admin.terms.flash.created');

                return $this->saved($request, $taxonomy, $term);
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->form($request, $taxonomy, null, $locale, $data->values, $errors, $params, '', 422);
    }

    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->termRequest->taxonomy($request);
        $term = $this->termRequest->term($request, $taxonomy);
        $this->termRequest->rememberLocale($request, $term);

        // A copy opened for machine translation (Plan 38: ?machine={source}); tags are never translated (Plan 35), and
        // the dialog is never reached by it (the translate actions open the full page).
        $machine = $this->collections->onlyTagged($taxonomy->name) || Dialog::requested($request) ? null : MachineTranslation::requested($request, $term->locale, $this->locales)?->forTerm();

        return $this->form($request, $taxonomy, $term, $term->locale, TermForm::values($term), [], [], self::version($term), machine: $machine);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->termRequest->taxonomy($request);
        $term = $this->termRequest->term($request, $taxonomy);
        $data = TermForm::parse($request);
        $errors = $data->version !== self::version($term) ? ['term' => 'validation.term.stale'] : $data->errors;
        $params = [];
        if ($errors === []) {
            try {
                $updated = $this->service->update($term, $data->input, CurrentUser::require($request), ClientIp::of($request));
                Flash::success(Session::of($request), 'admin.terms.flash.saved');

                return $this->saved($request, $taxonomy, $updated);
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->form($request, $taxonomy, $term, $term->locale, $data->values, $errors, $params, $data->version, 422);
    }

    /**
     * @param TermFormValues $values
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     * @param MachineTranslation|null $machine the machine translation panel of a copy (Plan 38)
     */
    private function form(
        ServerRequestInterface $request,
        Taxonomy $taxonomy,
        ?Term $term,
        string $locale,
        array $values,
        array $errors,
        array $params,
        string $version,
        int $status = 200,
        ?MachineTranslation $machine = null,
    ): ResponseInterface {
        // In the dialog a refusal without a field (a stale form, a gone item, a crafted post) reloads the screen with
        // it as an error toast, like the media folder dialog; field errors come back into the dialog.
        [, $toasts] = FormErrors::split($errors, $params, TermForm::fields($taxonomy));
        if ($toasts !== [] && Dialog::requested($request)) {
            foreach ($toasts as $toast) {
                Flash::error(Session::of($request), $toast->key, $toast->params);
            }

            return Dialog::redirect($this->responses, Dialog::back($request, $this->router->url(...TermRoutes::of($taxonomy, '', ['locale' => $locale]))));
        }
        $parents = [];
        if ($taxonomy->hierarchical) {
            // The term itself and its descendants are left out; TermService refuses them as a parent anyway
            // (it would create a cycle), but offering them invites a confusing round trip through that error.
            // TermTree::flatten() is depth-first, so once the term is found, every following node deeper than
            // it is one of its descendants; the first node back at its depth (or shallower) ends the skip.
            $skipDepth = null;
            foreach (TermTree::flatten($this->terms->forTaxonomy($taxonomy->name, $locale)) as $node) {
                if ($skipDepth !== null && $node['depth'] > $skipDepth) {
                    continue;
                }
                $skipDepth = null;
                if ($term !== null && $node['term']->id === $term->id) {
                    $skipDepth = $node['depth'];

                    continue;
                }
                $parents[] = $node;
            }
        }
        $translations = [];
        foreach ($term === null ? [] : $this->terms->translations($term->translationGroup) as $translation) {
            $translations[$translation->locale] = $translation;
        }

        $dialog = Dialog::requested($request);

        return $this->responses->html($this->view->render($dialog ? 'terms/dialog' : 'terms/form', $request, [
            'taxonomy' => $taxonomy,
            'term' => $term,
            'locale' => $locale,
            'locales' => $this->locales->all(),
            'translations' => $translations,
            // Tags are kept separately per language (Plan 35): their form offers no translating.
            'translatable' => !$this->collections->onlyTagged($taxonomy->name),
            'values' => $values,
            'errors' => $errors,
            'params' => $params,
            'version' => $version,
            'parents' => $parents,
            'viewUrl' => $term === null ? null : $this->urls->path($term),
            'machine' => $machine,
        ]), $dialog ? 200 : $status);
    }

    /**
     * After a save: the term's edit page, or from the dialog the list in its language, where the change shows.
     */
    private function saved(ServerRequestInterface $request, Taxonomy $taxonomy, Term $term): ResponseInterface
    {
        return Dialog::requested($request)
            ? Dialog::redirect($this->responses, Dialog::back($request, $this->router->url(...TermRoutes::of($taxonomy, '', ['locale' => $term->locale]))))
            : $this->responses->redirect($this->router->url(...TermRoutes::of($taxonomy, '.edit', ['id' => $term->id])), 303);
    }

    private static function version(Term $term): string
    {
        return $term->updatedAt->format('Y-m-d H:i:s');
    }
}
