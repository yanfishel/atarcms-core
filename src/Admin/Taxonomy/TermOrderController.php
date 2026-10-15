<?php

declare(strict_types=1);

namespace AtarCms\Admin\Taxonomy;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\TreeForm;
use AtarCms\Admin\TreeResponder;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermService;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The order screen of a hierarchical taxonomy: every term of one locale in the tree editor.
 * Routes require "taxonomies.manage".
 */
final class TermOrderController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly TermRequest $termRequest,
        private readonly TermRepository $terms,
        private readonly TermService $service,
        private readonly TreeResponder $tree,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->hierarchicalTaxonomy($request);
        $locale = $this->termRequest->contentLocale($request);

        return $this->page($request, $taxonomy, $locale, [], [], 200);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $taxonomy = $this->hierarchicalTaxonomy($request);
        $form = TreeForm::parse($request);
        $locale = $this->termRequest->locale($form->locale);
        $errors = $form->errors;
        $params = [];
        if ($form->tree !== null) {
            try {
                $this->service->reorder($taxonomy, $locale, $form->tree, $form->version, CurrentUser::require($request), ClientIp::of($request));

                return $this->tree->saved(
                    $request,
                    fn(): string => TreeOrder::version($this->terms->forTaxonomy($taxonomy->name, $locale)),
                    $this->router->url('admin.terms.order', ['taxonomy' => $taxonomy->name, 'locale' => $locale]),
                );
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->tree->failed($request, $errors, $params, fn(): ResponseInterface => $this->page($request, $taxonomy, $locale, $errors, $params, 422));
    }

    private function hierarchicalTaxonomy(ServerRequestInterface $request): Taxonomy
    {
        $taxonomy = $this->termRequest->taxonomy($request);
        if (!$taxonomy->hierarchical) {
            throw new NotFound(sprintf('Taxonomy "%s" is flat', $taxonomy->name));
        }

        return $taxonomy;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function page(ServerRequestInterface $request, Taxonomy $taxonomy, string $locale, array $errors, array $params, int $status): ResponseInterface
    {
        $terms = $this->terms->forTaxonomy($taxonomy->name, $locale);
        $items = [];
        foreach ($terms as $term) {
            $items[$term->id] = [
                'label' => $term->name,
                'url' => $this->router->url('admin.terms.edit', ['taxonomy' => $taxonomy->name, 'id' => $term->id]),
                'meta' => $term->slug,
                'hidden' => false,
            ];
        }

        return $this->responses->html($this->view->render('terms/order', $request, [
            'taxonomy' => $taxonomy,
            'locale' => $locale,
            'tree' => TreeOrder::nest($terms),
            'items' => $items,
            'version' => TreeOrder::version($terms),
            'maxDepth' => TreeOrder::MAX_DEPTH,
            'errors' => $errors,
            'params' => $params,
        ]), $status);
    }
}
