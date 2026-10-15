<?php

declare(strict_types=1);

namespace AtarCms\Admin\Taxonomy;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Dialog;
use AtarCms\Admin\Flash;
use AtarCms\Admin\MachineTranslation;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Collections;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermService;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Term actions besides editing: copy for translation (and machine translation, Plan 38), delete (with a confirmation page). Requested for the dialog
 * (Dialog, the Sections screen), the confirmation is the dialog's fragment, and a done or refused delete sends the
 * browser back to the list with its flash (the reason of a refusal is an error toast). Routes require
 * "taxonomies.manage".
 */
final class TermActionsController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly TermRequest $termRequest,
        private readonly TermRepository $terms,
        private readonly TermService $service,
        private readonly Collections $collections,
    ) {}

    public function translate(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->termRequest->taxonomy($request);
        $term = $this->termRequest->term($request, $taxonomy);
        if ($this->collections->onlyTagged($taxonomy->name)) {
            // Tags are kept separately per language (Plan 35): there is nothing to translate.
            throw new NotFound();
        }
        $body = $request->getParsedBody();
        $locale = is_array($body) && is_string($body['locale'] ?? null) ? $body['locale'] : '';

        try {
            $translation = $this->service->createTranslation($term, $locale, CurrentUser::require($request), ClientIp::of($request));
        } catch (ValidationFailed $e) {
            Flash::refusal(Session::of($request), $e);

            return $this->responses->redirect($this->router->url(...TermRoutes::of($taxonomy, '.edit', ['id' => $term->id])), 303);
        }
        Flash::success(Session::of($request), 'admin.terms.flash.translated');

        // The machine button: the copy's form translates it in the browser from the source language.
        $machine = MachineTranslation::chosen($request) ? MachineTranslation::query($term->locale) : [];

        return $this->responses->redirect($this->router->url(...TermRoutes::of($taxonomy, '.edit', ['id' => $translation->id, 'locale' => $translation->locale, ...$machine])), 303);
    }

    public function confirmDelete(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->termRequest->taxonomy($request);

        return $this->confirmation($request, $taxonomy, $this->termRequest->term($request, $taxonomy));
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->termRequest->taxonomy($request);
        $term = $this->termRequest->term($request, $taxonomy);

        $list = Dialog::back($request, $this->router->url(...TermRoutes::of($taxonomy, '', ['locale' => $term->locale])));
        try {
            $this->service->delete($term, CurrentUser::require($request), ClientIp::of($request));
        } catch (ValidationFailed $e) {
            // An error toast: the full page returns to the term's form (where its Cancel leads); from the dialog the
            // screen behind it is out of date (its Delete was enabled), so it is reloaded.
            Flash::refusal(Session::of($request), $e);

            return Dialog::requested($request)
                ? Dialog::redirect($this->responses, $list)
                : $this->responses->redirect($this->router->url(...TermRoutes::of($taxonomy, '.edit', ['id' => $term->id])), 303);
        }
        Flash::success(Session::of($request), 'admin.terms.flash.deleted');

        return Dialog::requested($request) ? Dialog::redirect($this->responses, $list) : $this->responses->redirect($list, 303);
    }

    /** The delete confirmation: the full page, or the dialog's fragment. */
    private function confirmation(ServerRequestInterface $request, Taxonomy $taxonomy, Term $term): ResponseInterface
    {
        $data = [
            'taxonomy' => $taxonomy,
            'term' => $term,
            'entries' => $this->terms->entryCounts([$term->id])[$term->id] ?? 0,
        ];

        return $this->responses->html(Dialog::requested($request)
            ? $this->view->render('terms/delete-dialog', $request, $data)
            : $this->view->render('terms/delete', $request, $data));
    }
}
