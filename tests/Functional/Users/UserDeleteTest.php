<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Users;

use AtarCms\Auth\Roles;
use AtarCms\Auth\UserRepository;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Browser;

/**
 * Deleting a user: a confirmation (the dialog's fragment, or a full page without JavaScript), then a hard delete
 * through UserManager. Their content stays without an author, their session ends, the history names them; nobody
 * deletes themselves or a user whose role they cannot grant.
 */
final class UserDeleteTest extends ContentFunctionalTestCase
{
    private const array HTMX = ['HX-Request' => 'true'];

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        // A manager may grant every default role but admin.
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'manager' => ['label' => 'Manager', 'permissions' => ['users.manage', 'entries.*.*', 'media.*', 'taxonomies.manage', 'menus.manage']],
        ]));
    }

    public function testTheDialogConfirmsAndDeletes(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        $bob = $this->createUser('bob<b>@example.com', 'editor', 'Bob');

        $confirm = (string) $this->browser->get('/admin/users/' . $bob->id . '/delete', self::HTMX)->getBody();
        self::assertStringNotContainsString('<html', $confirm);
        self::assertStringContainsString('<h2 id="dialog-title">Delete user</h2>', $confirm);
        self::assertStringContainsString('Delete bob&lt;b&gt;@example.com? Their entries and files stay, without an author. This cannot be undone.', $confirm);
        self::assertStringContainsString('hx-post="/admin/users/' . $bob->id . '/delete" hx-target="closest [data-dialog-content]"', $confirm);
        self::assertStringContainsString('data-dialog-close autofocus>Cancel</button>', $confirm);

        $deleted = $this->browser->post('/admin/users/' . $bob->id . '/delete', ['_csrf' => $this->csrfToken()], self::HTMX);
        self::assertSame(200, $deleted->getStatusCode());
        self::assertSame('/admin/users', $deleted->getHeaderLine('HX-Redirect'));
        self::assertNull($this->users()->find($bob->id));
        self::assertMatchesRegularExpression('~admin-toast-success.*User deleted\.~s', (string) $this->browser->get('/admin/users')->getBody());
    }

    public function testWithoutJavaScriptTheConfirmationIsAPage(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        $bob = $this->createUser('bob@example.com', 'editor', 'Bob');

        $page = (string) $this->browser->get('/admin/users/' . $bob->id . '/delete')->getBody();
        self::assertStringContainsString('<html', $page);
        self::assertStringContainsString('<section class="admin-card admin-card-danger admin-confirm">', $page);
        self::assertStringContainsString('<form class="admin-confirm-actions" method="post" action="/admin/users/' . $bob->id . '/delete">', $page);
        self::assertStringContainsString('<a class="admin-button admin-button-secondary" href="/admin/users/' . $bob->id . '">Cancel</a>', $page);

        $deleted = $this->browser->post('/admin/users/' . $bob->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/admin/users', $deleted->getHeaderLine('Location'));
        self::assertNull($this->users()->find($bob->id));
        self::assertStringContainsString('User deleted.', (string) $this->browser->get('/admin/users')->getBody());
    }

    public function testTheEditPageLinksToTheConfirmationExceptForOneself(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);
        $bob = $this->createUser('bob@example.com', 'editor');

        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-link admin-button-danger" href="/admin/users/' . $bob->id . '/delete"><svg[^>]*><use href="[^"]+#trash-2"></use></svg>Delete</a>~', (string) $this->browser->get('/admin/users/' . $bob->id)->getBody());
        self::assertStringNotContainsString('/delete"', (string) $this->browser->get('/admin/users/' . $ann->id)->getBody());
    }

    public function testTheContentStaysWithoutAnAuthorAndTheHistoryNamesTheUser(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        $bob = $this->createUser('bob@example.com', 'editor', 'Bob');
        $entry = $this->entry('articles', 'Bob’s article', ['author' => $bob]);
        self::assertStringContainsString('<td data-label="Author">Bob</td>', (string) $this->browser->get('/admin/content/articles')->getBody());

        $this->browser->post('/admin/users/' . $bob->id . '/delete', ['_csrf' => $this->csrfToken()]);

        self::assertNull($this->db->scalar('SELECT author_id FROM entries WHERE id = ?', [$entry->id]));
        $list = (string) $this->browser->get('/admin/content/articles')->getBody();
        self::assertStringContainsString('Bob’s article', $list);
        self::assertStringContainsString('<td data-label="Author"></td>', $list);
        $history = (string) $this->browser->get('/admin/audit')->getBody();
        self::assertMatchesRegularExpression('~User deleted.*bob@example\.com~s', $history);
    }

    public function testTheDeletedUserIsSignedOut(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        $bob = $this->createUser('bob@example.com', 'editor');
        $bobBrowser = new Browser($this->app);
        $this->actingAs($bob, $bobBrowser);
        self::assertSame(200, $bobBrowser->get('/admin')->getStatusCode());

        $this->browser->post('/admin/users/' . $bob->id . '/delete', ['_csrf' => $this->csrfToken()]);

        $next = $bobBrowser->get('/admin');
        self::assertSame(302, $next->getStatusCode());
        self::assertStringStartsWith('/admin/login', $next->getHeaderLine('Location'));
    }

    public function testNobodyDeletesThemselves(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        $dialog = $this->browser->post('/admin/users/' . $ann->id . '/delete', ['_csrf' => $this->csrfToken()], self::HTMX);
        self::assertSame('/admin/users', $dialog->getHeaderLine('HX-Redirect'));
        self::assertMatchesRegularExpression('~admin-toast-error.*You cannot delete your own account\.~s', (string) $this->browser->get('/admin/users')->getBody());

        $page = $this->browser->post('/admin/users/' . $ann->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $page->getStatusCode());
        self::assertSame('/admin/users', $page->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('~admin-toast-error.*You cannot delete your own account\.~s', (string) $this->browser->get('/admin/users')->getBody());
        self::assertNotNull($this->users()->find($ann->id));
        self::assertNotContains('user.deleted', $this->auditActions());
    }

    public function testOnesOwnConfirmationIsTheRefusalToast(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        $dialog = $this->browser->get('/admin/users/' . $ann->id . '/delete', self::HTMX);
        self::assertSame('/admin/users', $dialog->getHeaderLine('HX-Redirect'));
        self::assertMatchesRegularExpression('~admin-toast-error.*You cannot delete your own account\.~s', (string) $this->browser->get('/admin/users')->getBody());

        $page = $this->browser->get('/admin/users/' . $ann->id . '/delete');
        self::assertSame(303, $page->getStatusCode());
        self::assertSame('/admin/users', $page->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('~admin-toast-error.*You cannot delete your own account\.~s', (string) $this->browser->get('/admin/users')->getBody());
    }

    public function testAUserWithARoleOneCannotGrantIsForbidden(): void
    {
        $admin = $this->createUser('root@example.com', 'admin');
        $this->actingAs($this->createUser('manager@example.com', 'manager'));

        self::assertSame(403, $this->browser->get('/admin/users/' . $admin->id . '/delete')->getStatusCode());
        self::assertSame(403, $this->browser->get('/admin/users/' . $admin->id . '/delete', self::HTMX)->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/users/' . $admin->id . '/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertNotNull($this->users()->find($admin->id));
    }

    public function testUnknownUsersCsrfAndPermissions(): void
    {
        $ann = $this->createUser('ann@example.com');
        $bob = $this->createUser('bob@example.com', 'editor');
        $this->actingAs($ann);

        self::assertSame(404, $this->browser->get('/admin/users/999/delete')->getStatusCode());
        self::assertSame(404, $this->browser->post('/admin/users/999/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertSame(419, $this->browser->post('/admin/users/' . $bob->id . '/delete')->getStatusCode(), 'no CSRF token');
        self::assertNotNull($this->users()->find($bob->id));

        $this->actingAs($bob);
        self::assertSame(403, $this->browser->get('/admin/users/' . $ann->id . '/delete')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/users/' . $ann->id . '/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertNotNull($this->users()->find($ann->id));
    }

    private function users(): UserRepository
    {
        return $this->app->container()->service(UserRepository::class);
    }
}
