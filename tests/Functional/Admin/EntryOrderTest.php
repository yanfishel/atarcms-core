<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;

final class EntryOrderTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'viewer' => ['label' => 'Viewer', 'permissions' => ['entries.pages.view']],
        ]));
    }

    public function testOnlyHierarchicalCollectionsAndUsersWhoEditThemGetTheOrderScreen(): void
    {
        $this->entry('pages', 'About', ['slug' => 'about']);
        $this->actingAs($this->createUser('vi@example.com', 'viewer'));

        self::assertSame(403, $this->browser->get('/admin/content/pages/order')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/content/pages/order', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => str_repeat('0', 40), 'tree' => '[]'])->getStatusCode());
        self::assertStringNotContainsString('/admin/content/pages/order', (string) $this->browser->get('/admin/content/pages')->getBody());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        foreach (['/admin/content/articles/order', '/admin/content/contact/order', '/admin/content/ghost/order'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
        self::assertStringContainsString('href="/admin/content/pages/order?locale=en"', (string) $this->browser->get('/admin/content/pages')->getBody());
        self::assertStringContainsString('href="/admin/content/pages/order?locale=ru"', (string) $this->browser->get('/admin/content/pages?locale=ru')->getBody());
        self::assertStringNotContainsString('/order', (string) $this->browser->get('/admin/content/articles')->getBody());
    }

    public function testShowsTheEscapedTreeAndSavesADrag(): void
    {
        $about = $this->entry('pages', 'About <b>', ['slug' => 'about', 'position' => 0]);
        $team = $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $draft = $this->entry('pages', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft, 'position' => 1]);
        $this->entry('pages', 'О нас', ['slug' => 'o-nas', 'locale' => 'ru']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/pages/order')->getBody();
        self::assertStringContainsString('<h1>Order of pages <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>', $body);
        self::assertMatchesRegularExpression('~<li><a href="/admin/content/pages\?locale=en">pages</a></li>\s*<li><span aria-current="page">Change order</span></li>~', $body);
        self::assertStringNotContainsString('Back to the list', $body);
        self::assertStringContainsString('<a class="admin-tree-label" href="/admin/content/pages/' . $about->id . '">About &lt;b&gt;</a>', $body);
        self::assertStringContainsString('<span class="admin-muted admin-tree-meta">team</span>', $body);
        self::assertStringContainsString('Not shown on the site', self::row($body, $draft));
        self::assertStringNotContainsString('hreflang=', $body, 'the top bar switches the language');
        self::assertStringContainsString('data-max-depth="32"', $body);
        self::assertStringNotContainsString('О нас', $body);
        self::assertStringContainsString('О нас', (string) $this->browser->get('/admin/content/pages/order?locale=ru')->getBody());

        $this->clock->advance('+1 minute');
        $response = $this->save(sprintf('[{"id":%d},{"id":%d},{"id":%d}]', $draft->id, $about->id, $team->id), self::version($body));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/content/pages/order?locale=en', $response->getHeaderLine('Location'));
        self::assertSame([[null, 0], [null, 1], [null, 2]], [$this->placement($draft), $this->placement($about), $this->placement($team)]);
        self::assertStringContainsString('Order saved.', (string) $this->browser->get('/admin/content/pages/order')->getBody());
    }

    public function testAuthorsMoveOnlyEntriesTheyCanEdit(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $first = $this->entry('pages', 'Mine one', ['slug' => 'mine-one', 'status' => EntryStatus::Draft, 'author' => $author, 'position' => 0]);
        $second = $this->entry('pages', 'Mine two', ['slug' => 'mine-two', 'status' => EntryStatus::Draft, 'author' => $author, 'position' => 1]);
        $theirs = $this->entry('pages', 'Theirs', ['slug' => 'theirs', 'position' => 2]);
        $this->actingAs($author);

        $body = (string) $this->browser->get('/admin/content/pages/order')->getBody();
        self::assertStringContainsString('<span class="admin-tree-label">Theirs</span>', $body, 'no edit link without the right to edit');
        self::assertSame(303, $this->save(sprintf('[{"id":%d},{"id":%d},{"id":%d}]', $second->id, $first->id, $theirs->id), self::version($body))->getStatusCode());
        self::assertSame([[null, 1], [null, 0], [null, 2]], [$this->placement($first), $this->placement($second), $this->placement($theirs)]);

        $body = (string) $this->browser->get('/admin/content/pages/order')->getBody();
        $refused = $this->save(sprintf('[{"id":%d},{"id":%d},{"id":%d}]', $theirs->id, $second->id, $first->id), self::version($body));
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('You cannot move “Theirs”.', (string) $refused->getBody());
        self::assertSame([[null, 1], [null, 0], [null, 2]], [$this->placement($first), $this->placement($second), $this->placement($theirs)]);
    }

    public function testMovesMakeOpenFormsStaleAndStaleTreesAreRefused(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about', 'position' => 0]);
        $team = $this->entry('pages', 'Team', ['slug' => 'team', 'position' => 1]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $body = (string) $this->browser->get('/admin/content/pages/order')->getBody();
        preg_match('~name="tree" value="([^"]*)"~', $body, $tree);

        $editForm = (string) $this->browser->get('/admin/content/pages/' . $team->id)->getBody();
        preg_match('~name="version" value="([^"]*)"~', $editForm, $editVersion);

        $this->clock->advance('+1 minute');
        $moved = $this->browser->post('/admin/content/pages/order', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => self::version($body),
            'tree' => html_entity_decode($tree[1] ?? '', ENT_QUOTES | ENT_HTML5),
            'move' => $team->id . ':indent',
        ]);
        self::assertSame(303, $moved->getStatusCode());
        self::assertSame([$about->id, 0], $this->placement($team));

        // The edit form opened before the move still carries the old version: saving it would move Team back.
        $staleForm = $this->browser->post('/admin/content/pages/' . $team->id, [
            '_csrf' => $this->csrfToken(),
            'version' => $editVersion[1] ?? '',
            'title' => 'Team',
            'status' => 'published',
            'parent_id' => '',
            'position' => '1',
        ]);
        self::assertSame(422, $staleForm->getStatusCode());
        self::assertStringContainsString('Someone else saved this entry after you opened it.', (string) $staleForm->getBody());

        $staleTree = $this->save(sprintf('[{"id":%d},{"id":%d}]', $team->id, $about->id), self::version($body));
        self::assertSame(422, $staleTree->getStatusCode());
        self::assertStringContainsString('Someone else changed the order after you opened this page.', (string) $staleTree->getBody());
        self::assertSame([$about->id, 0], $this->placement($team));
    }

    public function testABackgroundSaveAnswersWithTheNewVersionOrTheReason(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about', 'position' => 0]);
        $team = $this->entry('pages', 'Team', ['slug' => 'team', 'position' => 1]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $loaded = self::version((string) $this->browser->get('/admin/content/pages/order')->getBody());
        $json = ['Accept' => 'application/json'];
        $tree = sprintf('[{"id":%d},{"id":%d}]', $team->id, $about->id);

        $saved = $this->browser->post('/admin/content/pages/order', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => $loaded, 'tree' => $tree], $json);

        self::assertSame(200, $saved->getStatusCode());
        self::assertSame(['version' => self::version((string) $this->browser->get('/admin/content/pages/order')->getBody())], json_decode((string) $saved->getBody(), true));
        self::assertSame([[null, 1], [null, 0]], [$this->placement($about), $this->placement($team)]);

        $stale = $this->browser->post('/admin/content/pages/order', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => $loaded, 'tree' => $tree], $json);
        self::assertSame(422, $stale->getStatusCode());
        self::assertSame(['error' => 'Someone else changed the order after you opened this page, so yours was not saved. Reload the page to see the current order.'], json_decode((string) $stale->getBody(), true));
    }

    private function save(string $tree, string $version): ResponseInterface
    {
        return $this->browser->post('/admin/content/pages/order', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => $version,
            'tree' => $tree,
        ]);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function placement(Entry $entry): array
    {
        $stored = $this->app->container()->service(EntryRepository::class)->find($entry->id);

        return [$stored?->parentId, $stored?->position];
    }

    private static function version(string $body): string
    {
        return preg_match('~name="version" value="([0-9a-f]{40})"~', $body, $match) === 1 ? $match[1] : self::fail('No tree version on the page');
    }

    /**
     * The row (handle, label, meta, badge, buttons) of one item in the tree editor.
     */
    private static function row(string $body, Entry $entry): string
    {
        return preg_match('~data-id="' . $entry->id . '">\s*<div class="admin-tree-row">(.*?)</div>~s', $body, $match) === 1
            ? $match[1]
            : self::fail('No row for entry #' . $entry->id);
    }
}
