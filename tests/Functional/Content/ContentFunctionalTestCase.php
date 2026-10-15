<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Auth\LoginService;
use AtarCms\Auth\User;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Menu\MenuItem;
use AtarCms\Content\Menu\MenuItemInput;
use AtarCms\Content\Menu\MenuRepository;
use AtarCms\Content\Menu\MenuService;
use AtarCms\Content\Menu\MenuTarget;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Term;
use AtarCms\Content\TermInput;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermService;
use AtarCms\Http\Security\CsrfToken;
use AtarCms\Http\Session\SessionMiddleware;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\FunctionalTestCase;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\FrozenClock;
use DateTimeImmutable;

abstract class ContentFunctionalTestCase extends FunctionalTestCase
{
    protected FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-28 12:00:00');
        parent::setUp();
    }

    protected function configureContainer(Container $container): void
    {
        $container->set(Clock::class, $this->clock);
    }

    /**
     * Like FunctionalTestCase::actingAs(), but stamps the session with the frozen clock's time: SessionMiddleware
     * compares it against Clock::now(), and the real wall clock can drift far enough from a frozen date to look
     * like an idle timeout.
     */
    protected function actingAs(User $user, ?Browser $browser = null): void
    {
        $id = bin2hex(random_bytes(32));
        $now = $this->clock->now()->getTimestamp();
        $this->sessions->write($id, [
            'data' => [LoginService::SESSION_KEY => $user->id, CsrfToken::SESSION_KEY => bin2hex(random_bytes(32))],
            'created_at' => $now,
            'last_seen' => $now,
        ]);
        ($browser ?? $this->browser)->setCookie(SessionMiddleware::SECURE_COOKIE, $id);
    }

    /**
     * Creates an entry through EntryService; published by default.
     *
     * @param array{locale?: string, slug?: string, status?: EntryStatus, publishedAt?: DateTimeImmutable, parent?: Entry, position?: int, fields?: array<string, mixed>, metaTitle?: string, metaDescription?: string, author?: User, terms?: array<string, list<int|string>>} $options
     */
    protected function entry(string $collection, string $title, array $options = []): Entry
    {
        $container = $this->app->container();

        return $container->service(EntryService::class)->create(
            $container->service(Collections::class)->get($collection),
            $options['locale'] ?? 'en',
            new EntryInput(
                title: $title,
                slug: $options['slug'] ?? '',
                status: $options['status'] ?? EntryStatus::Published,
                publishedAt: $options['publishedAt'] ?? null,
                parentId: isset($options['parent']) ? $options['parent']->id : null,
                position: $options['position'] ?? 0,
                fields: $options['fields'] ?? [],
                metaTitle: $options['metaTitle'] ?? '',
                metaDescription: $options['metaDescription'] ?? '',
                terms: $options['terms'] ?? null,
            ),
            $options['author'] ?? null,
            null,
        );
    }

    /**
     * Creates a term through TermService.
     *
     * @param array{locale?: string, slug?: string, parent?: Term, position?: int, metaTitle?: string, metaDescription?: string} $options
     */
    protected function term(string $taxonomy, string $name, array $options = []): Term
    {
        $container = $this->app->container();

        return $container->service(TermService::class)->create(
            $container->service(Taxonomies::class)->get($taxonomy),
            $options['locale'] ?? 'en',
            new TermInput($name, $options['slug'] ?? '', $options['parent']->id ?? null, $options['position'] ?? 0, $options['metaTitle'] ?? '', $options['metaDescription'] ?? ''),
            null,
            null,
        );
    }

    protected function terms(): TermRepository
    {
        return $this->app->container()->service(TermRepository::class);
    }

    /**
     * Creates a menu item through MenuService (appended to the top level). "parent" and "position" are then set
     * directly: in the admin the tree editor places items.
     *
     * @param array{locale?: string, entry?: Entry, term?: Term, url?: string, newTab?: bool, parent?: MenuItem, position?: int} $options
     */
    protected function menuItem(string $location, string $label, array $options = []): MenuItem
    {
        $container = $this->app->container();
        $target = isset($options['entry']) ? MenuTarget::Entry : (isset($options['term']) ? MenuTarget::Term : MenuTarget::Url);
        $item = $container->service(MenuService::class)->create($location, $options['locale'] ?? 'en', new MenuItemInput(
            label: $label,
            target: $target,
            entryId: $options['entry']->id ?? null,
            termId: $options['term']->id ?? null,
            url: $options['url'] ?? '',
            newTab: $options['newTab'] ?? false,
        ), null, null);
        if (!isset($options['parent']) && !isset($options['position'])) {
            return $item;
        }
        $this->menus()->update($item->id, ['parent_id' => $options['parent']->id ?? null, 'position' => $options['position'] ?? $item->position]);

        return $this->menus()->find($item->id) ?? self::fail('The menu item disappeared');
    }

    protected function menus(): MenuRepository
    {
        return $this->app->container()->service(MenuRepository::class);
    }

    /**
     * Translates $source into $locale and saves the translation with its own title and slug.
     */
    protected function translation(Entry $source, string $locale, string $title, string $slug, EntryStatus $status = EntryStatus::Published): Entry
    {
        $service = $this->app->container()->service(EntryService::class);
        $draft = $service->createTranslation($source, $locale, null, null);

        return $service->update($draft, new EntryInput(
            title: $title,
            slug: $slug,
            status: $status,
            parentId: $draft->parentId,
            fields: $draft->data,
        ), null, null);
    }
}
