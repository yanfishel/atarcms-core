<?php

declare(strict_types=1);

namespace AtarCms\Admin\Taxonomy;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\TreeForm;
use AtarCms\Admin\TreeResponder;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermEntryOrder;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TreeNode;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Order articles" of a section (/admin/sections/{id}/order): its articles in its language, any status, in the tree
 * editor as a flat list (TermEntryOrder). Only a section without subsections has one (articles belong there), and only
 * while collections list their entries on the section pages; otherwise 404. Routes require "taxonomies.manage".
 */
final class SectionArticlesController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly TermRequest $termRequest,
        private readonly TermRepository $terms,
        private readonly Collections $collections,
        private readonly EntryAccess $access,
        private readonly TermEntryOrder $order,
        private readonly TreeResponder $tree,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$sections, $section] = $this->section($request);

        return $this->page($request, $sections, $section, [], [], 200);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        [$sections, $section] = $this->section($request);
        $form = TreeForm::parse($request);
        $errors = $form->errors;
        $params = [];
        if ($form->tree !== null) {
            try {
                $ids = [];
                foreach ($form->tree as $node) {
                    if ($node->children !== []) {
                        throw new ValidationFailed(['tree' => 'validation.tree.invalid']);
                    }
                    $ids[] = $node->id;
                }
                $this->order->reorder($section, $ids, $form->version, CurrentUser::require($request), ClientIp::of($request));

                return $this->tree->saved(
                    $request,
                    fn(): string => TermEntryOrder::version($this->order->entries($section)),
                    $this->router->url('admin.sections.articles', ['id' => $section->id]),
                );
            } catch (ValidationFailed $e) {
                [$errors, $params] = [$e->errors, $e->params];
            }
        }

        return $this->tree->failed($request, $errors, $params, fn(): ResponseInterface => $this->page($request, $sections, $section, $errors, $params, 422));
    }

    /**
     * @return array{0: Taxonomy, 1: Term}
     */
    private function section(ServerRequestInterface $request): array
    {
        $sections = $this->termRequest->taxonomy($request);
        $section = $this->termRequest->term($request, $sections);
        if ($this->collections->listedBy($sections->name) === [] || $this->terms->hasChildren($section->id)) {
            throw new NotFound(sprintf('Section #%d holds no articles to order', $section->id));
        }

        return [$sections, $section];
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function page(ServerRequestInterface $request, Taxonomy $sections, Term $section, array $errors, array $params, int $status): ResponseInterface
    {
        $user = CurrentUser::require($request);
        $entries = $this->order->entries($section);
        $items = [];
        foreach ($entries as $entry) {
            $items[$entry->id] = [
                'label' => $entry->title,
                'url' => $this->access->canEdit($user, $entry)
                    ? $this->router->url('admin.entries.edit', ['collection' => $entry->collection->name, 'id' => $entry->id])
                    : null,
                'meta' => '',
                'hidden' => false,
                'status' => $entry->status,
            ];
        }

        return $this->responses->html($this->view->render('sections/articles', $request, [
            'taxonomy' => $sections,
            'section' => $section,
            'tree' => array_map(static fn(Entry $entry): TreeNode => new TreeNode($entry->id, []), $entries),
            'items' => $items,
            'version' => TermEntryOrder::version($entries),
            'errors' => $errors,
            'params' => $params,
        ]), $status);
    }
}
