<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Admin\Taxonomy\TermRoutes;
use AtarCms\Auth\Authorizer;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\PreviewEntry;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Http\RequestMemo;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\Translator;
use AtarCms\Messages\MessageRepository;
use AtarCms\Messages\MessageService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The quick actions a signed-in user gets on the public site (theme helper adminBar()): the admin dashboard, "Add" for
 * each routed collection they may create in (at most MAX_ADD, in config order, opening in the page's language), "Edit"
 * for the page's entry or term when they may edit it, and the messages with the unread count for those who read
 * them. Nothing for a visitor (no query) or on a stateless route (no session, no user). Labels are in the page's
 * language.
 */
final readonly class AdminBar
{
    public const int MAX_ADD = 3;

    public function __construct(
        private Collections $collections,
        private Taxonomies $taxonomies,
        private EntryAccess $access,
        private Authorizer $authorizer,
        private Router $router,
        private Translator $translator,
        private MessageRepository $messages,
    ) {}

    /**
     * @param Entry|null $entry the page's entry (an entry or single() page)
     * @param Term|null $term the page's term (a section or tag page)
     *
     * @return list<AdminBarAction> empty for a visitor
     */
    public function actions(ServerRequestInterface $request, ?Entry $entry = null, ?Term $term = null): array
    {
        $user = CurrentUser::of($request);
        if ($user === null || !$user->isActive()) {
            return [];
        }
        $locale = RequestLocale::of($request)->code;

        $actions = [new AdminBarAction(AdminBarAction::DASHBOARD, $this->router->url('admin.dashboard'), $this->t($locale, 'adminbar.dashboard'), 'layout-dashboard')];
        $added = 0;
        foreach ($this->collections->all() as $collection) {
            if ($added >= self::MAX_ADD || $collection->route === null || $collection->single || !$this->access->canCreate($user, $collection)) {
                continue;
            }
            $actions[] = new AdminBarAction(
                AdminBarAction::ADD,
                $this->router->url('admin.entries.new', ['collection' => $collection->name, 'locale' => $locale]),
                $this->t($locale, 'adminbar.add', ['collection' => $this->translator->label($locale, $collection->label)]),
                $collection->adminIcon(),
            );
            ++$added;
        }
        $actions[] = $this->edit($request, $user, $entry, $term, $locale);
        if ($this->authorizer->can($user, MessageService::PERMISSION)) {
            $unread = RequestMemo::through($request, 'admin-bar:unread', fn(): int => $this->messages->unreadCount());
            $actions[] = new AdminBarAction(
                AdminBarAction::MESSAGES,
                $this->router->url('admin.messages'),
                $unread > 0 ? $this->t($locale, 'adminbar.messages_unread', ['count' => $unread]) : $this->t($locale, 'adminbar.messages'),
                'inbox',
                $unread > 0 ? ($unread > 99 ? '99+' : (string) $unread) : null,
            );
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function t(string $locale, string $key, array $params = []): string
    {
        return $this->translator->translate($locale, $key, $params);
    }

    /**
     * Edit for the page's entry or term, or an inactive Edit saying why: the user may not edit it, or the page has
     * nothing to edit (a list, the search, a preview, a single() page without its entry).
     */
    private function edit(ServerRequestInterface $request, \AtarCms\Auth\User $user, ?Entry $entry, ?Term $term, string $locale): AdminBarAction
    {
        $entry = $entry !== null && $entry->id > 0 && PreviewEntry::of($request) === null ? $entry : null;
        if ($entry !== null && $this->access->canView($user, $entry->collection) && $this->access->canEdit($user, $entry)) {
            return new AdminBarAction(
                AdminBarAction::EDIT,
                $this->router->url('admin.entries.edit', ['collection' => $entry->collection->name, 'id' => $entry->id]),
                $this->translator->translate($locale, 'adminbar.edit'),
                'pencil',
            );
        }
        $taxonomy = $term === null ? null : $this->taxonomies->find($term->taxonomy);
        if ($term !== null && $taxonomy instanceof Taxonomy && $this->authorizer->can($user, Taxonomy::PERMISSION)) {
            [$route, $params] = TermRoutes::of($taxonomy, '.edit', ['id' => $term->id]);

            return new AdminBarAction(
                AdminBarAction::EDIT,
                $this->router->url($route, $params),
                $this->translator->translate($locale, $taxonomy->isRootTree() ? 'adminbar.edit_section' : 'adminbar.edit_term'),
                'pencil',
            );
        }

        return new AdminBarAction(
            AdminBarAction::EDIT,
            null,
            $this->translator->translate($locale, $entry !== null || $taxonomy instanceof Taxonomy ? 'adminbar.edit_denied' : 'adminbar.edit_none'),
            'pencil',
        );
    }
}
