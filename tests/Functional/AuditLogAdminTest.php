<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\AuditLog;
use AtarCms\I18n\Translator;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

final class AuditLogAdminTest extends FunctionalTestCase
{
    use TempDirectory;

    public function testAdminSeesEntriesNewestFirstAndEscaped(): void
    {
        $admin = $this->createUser('ann@example.com');
        $this->audit()->record('first.action');
        $this->audit()->record('user.created', $admin->id, 'user', 5, ['name' => '<script>alert(1)</script>'], '203.0.113.7');
        $this->actingAs($admin);

        $response = $this->browser->get('/admin/audit');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertLessThan(strpos($body, 'first.action'), strpos($body, 'user.created'));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringContainsString('<td data-label="Who">ann@example.com</td>', $body);
        self::assertStringContainsString('<details class="admin-details"><summary>Show</summary><code>{&quot;name&quot;:&quot;&lt;script&gt;', $body);
        self::assertStringContainsString('<td data-label="Item">User #5</td>', $body, 'no e-mail in the meta: the id');
        self::assertStringContainsString('<td data-label="What happened"><span title="user.created">User added</span></td>', $body);
        self::assertStringContainsString('<td data-label="What happened"><code>first.action</code></td>', $body, 'an action without a translation (a module\'s) shows its code');
        self::assertStringContainsString('203.0.113.7', $body);
        self::assertMatchesRegularExpression(self::navLink('/admin/audit', 'Activity history', true), $body);
    }

    /**
     * A file's alt texts and a user's details change through their services, which record the item's name: the history
     * names the file and the user, not "File #id" / "User #id".
     */
    public function testUpdatesNameTheirSubject(): void
    {
        $this->app->container()->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
        $media = $this->app->container()->service(MediaService::class);
        $file = $media->upload(TestImages::uploaded(TestImages::jpeg(), 'sea.jpg', 'image/jpeg'), null, null);
        $media->updateAlt($file, ['en' => 'The sea'], MediaService::version($file), null, null);
        $admin = $this->createUser('ann@example.com');
        $bob = $this->createUser('bob@example.com', 'editor', 'Bob');
        $this->actingAs($admin);
        $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => 'Robert', 'role' => 'editor', 'status' => 'active', 'original_status' => 'active']);
        $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => 'Robert', 'role' => 'editor', 'status' => 'disabled', 'original_status' => 'active']);
        $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => 'Robert', 'role' => 'editor', 'status' => 'active', 'original_status' => 'disabled']);

        $body = (string) $this->browser->get('/admin/audit')->getBody();

        foreach (['media.updated' => 'File: “sea.jpg”', 'user.updated' => 'User: “bob@example.com”', 'user.enabled' => 'User: “bob@example.com”'] as $action => $item) {
            self::assertMatchesRegularExpression('~<span title="' . preg_quote($action, '~') . '">[^<]+</span></td>\s*<td data-label="Item">' . $item . '</td>~', $body, $action);
        }
        self::assertStringNotContainsString('File #' . $file->id . '<', $body);
        self::assertStringNotContainsString('User #' . $bob->id . '<', $body);
    }

    /**
     * Actions read as plain words (the code stays as the tooltip), and the item by its name: the entry's title, the
     * file's name, the user's e-mail, the section's name.
     */
    public function testActionsAreTranslatedAndItemsNamed(): void
    {
        $admin = $this->createUser('ann@example.com');
        $this->audit()->record('entry.created', $admin->id, 'entry', 6, ['collection' => 'articles', 'locale' => 'en', 'title' => 'Hello <b>', 'status' => 'draft']);
        $this->audit()->record('media.uploaded', $admin->id, 'media', 3, ['name' => 'sea.jpg', 'mime' => 'image/jpeg', 'size' => 10]);
        $this->audit()->record('auth.login', $admin->id, 'user', $admin->id, ['method' => 'google']);
        $this->audit()->record('entry.reordered', $admin->id, 'collection', 'pages', ['collection' => 'pages', 'locale' => 'en', 'moved' => 2]);
        $this->audit()->record('shop.paid', null, 'order', 12);
        $this->actingAs($admin);

        $body = (string) $this->browser->get('/admin/audit')->getBody();

        foreach ([
            ['entry.created', 'Created', 'Entry: “Hello &lt;b&gt;”'],
            ['media.uploaded', 'Uploaded', 'File: “sea.jpg”'],
            ['auth.login', 'Signed in', 'User: “ann@example.com”'],
            ['entry.reordered', 'Order changed', 'Section: “pages”'],
        ] as [$action, $label, $item]) {
            self::assertStringContainsString('<td data-label="What happened"><span title="' . $action . '">' . $label . '</span></td>', $body, $action);
            self::assertStringContainsString('<td data-label="Item">' . $item . '</td>', $body, $action);
        }
        self::assertStringContainsString('<td data-label="Item">order #12</td>', $body, 'an unknown kind of item stays as it was recorded');
        self::assertStringNotContainsString('entry #6', $body);
    }

    public function testEveryCoreActionAndItemKindIsTranslatedInBothLanguages(): void
    {
        $actions = [
            'auth.login', 'auth.logout', 'auth.login_failed', 'auth.login_link_created',
            'user.created', 'user.updated', 'user.disabled', 'user.enabled',
            'entry.created', 'entry.updated', 'entry.deleted', 'entry.translated', 'entry.restored', 'entry.reordered',
            'term.created', 'term.updated', 'term.deleted', 'term.translated', 'term.reordered',
            'menu_item.created', 'menu_item.updated', 'menu_item.deleted', 'menu.reordered',
            'media.uploaded', 'media.updated', 'media.deleted',
        ];
        // Plus every literal code passed straight to an audit record() call in src/ (a code chosen by a match is listed above).
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php'
                && preg_match_all("/audit->record\(\s*'([a-z_]+\.[a-z_]+)'/", (string) file_get_contents($file->getPathname()), $found) > 0) {
                array_push($actions, ...$found[1]);
            }
        }
        $actions = array_values(array_unique($actions));
        $kinds = ['entry', 'term', 'menu_item', 'menu', 'media', 'user', 'collection', 'taxonomy'];
        $translator = $this->app->container()->service(Translator::class);

        foreach (['en', 'ru'] as $locale) {
            foreach ($actions as $action) {
                self::assertTrue($translator->canTranslate($locale, 'admin.audit.action.' . $action), $locale . ': ' . $action);
            }
            foreach ($kinds as $kind) {
                self::assertTrue($translator->canTranslate($locale, 'admin.audit.subject.' . $kind), $locale . ': ' . $kind);
            }
        }
    }

    public function testEditorCannotSeeAuditLog(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertSame(403, $this->browser->get('/admin/audit')->getStatusCode());
        self::assertStringNotContainsString('>Activity history</span></a>', (string) $this->browser->get('/admin')->getBody());
    }

    public function testPaginatesFiftyPerPage(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        for ($i = 1; $i <= 51; $i++) {
            $this->audit()->record('bulk.action', meta: ['i' => $i]);
        }

        $first = (string) $this->browser->get('/admin/audit')->getBody();
        $second = (string) $this->browser->get('/admin/audit?page=2')->getBody();

        self::assertSame(50, substr_count($first, 'class="audit-entry"'));
        self::assertStringContainsString('<a class="admin-page-link" href="/admin/audit?page=2" rel="next" title="Next page">', $first);
        self::assertStringContainsString('Page 1 of 2', $first);
        self::assertSame(1, substr_count($second, 'class="audit-entry"'));
        self::assertStringContainsString('{&quot;i&quot;:1}', $second);
        self::assertStringContainsString('<a class="admin-page-link" href="/admin/audit?page=1" rel="prev" title="Previous page">', $second);
    }

    public function testInvalidPagesAreClamped(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        self::assertStringContainsString('Nothing has happened yet.', (string) $this->browser->get('/admin/audit')->getBody());
        foreach (['abc', '0', '-3', '999', '1e3', '99999999999999999999'] as $page) {
            $response = $this->browser->get('/admin/audit?page=' . $page);

            self::assertSame(200, $response->getStatusCode(), $page);
            self::assertStringContainsString('Nothing has happened yet.', (string) $response->getBody(), $page);
            self::assertStringNotContainsString('admin-pagination', (string) $response->getBody(), 'one page needs no page links');
        }
    }

    private function audit(): AuditLog
    {
        return $this->app->container()->service(AuditLog::class);
    }
}
