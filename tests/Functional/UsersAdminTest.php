<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\Cast;

final class UsersAdminTest extends FunctionalTestCase
{
    public function testAdminSeesEscapedUserList(): void
    {
        $admin = $this->createUser('ann@example.com', 'admin', 'Ann');
        $bob = $this->createUser('bob@example.com', 'editor', 'Bob <script>');
        $this->actingAs($admin);

        $response = $this->browser->get('/admin/users');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a class="admin-row-link" href="/admin/users/' . $bob->id . '" data-dialog-open>bob@example.com</a>', $body);
        self::assertStringContainsString('Bob &lt;script&gt;', $body);
        self::assertStringNotContainsString('Bob <script>', $body);
        self::assertStringContainsString('<td data-label="Role"><span class="admin-chip admin-role admin-role-editor">Editor</span></td>', $body);
        self::assertStringContainsString('<span class="admin-chip admin-chip-active">Active</span>', $body);
        self::assertMatchesRegularExpression(self::navLink('/admin/users', 'Users', true), $body);
    }

    public function testEditorsAndAuthorsCannotManageUsers(): void
    {
        foreach (['editor', 'author'] as $role) {
            $this->actingAs($this->createUser($role . '@example.com', $role));

            $forbidden = $this->browser->get('/admin/users');
            self::assertSame(403, $forbidden->getStatusCode(), $role);
            self::assertStringContainsString('class="admin-error"', (string) $forbidden->getBody());
            self::assertSame(403, $this->browser->get('/admin/users/new')->getStatusCode(), $role);
            self::assertStringNotContainsString('href="/admin/users"', (string) $this->browser->get('/admin')->getBody(), $role);
        }

        $this->actingAs($this->createUser('ann@example.com', 'admin'));
        self::assertMatchesRegularExpression(self::navLink('/admin/users', 'Users'), (string) $this->browser->get('/admin')->getBody());
    }

    public function testAdminCreatesUser(): void
    {
        $admin = $this->createUser('ann@example.com');
        $this->actingAs($admin);

        $form = $this->browser->get('/admin/users/new');
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('<option value="editor">Editor</option>', (string) $form->getBody());
        self::assertStringContainsString('<option value="author">Author</option>', (string) $form->getBody());
        self::assertStringContainsString('<option value="" selected disabled>Choose a role</option>', (string) $form->getBody());
        foreach (['log-in' => 'email', 'type' => 'name', 'users' => 'role'] as $icon => $field) {
            self::assertMatchesRegularExpression('~<label for="' . $field . '"><svg[^>]*><use href="[^"]+#' . $icon . '"~', (string) $form->getBody(), $field);
        }
        self::assertStringContainsString('The role decides what this person can do in the admin.', (string) $form->getBody());
        self::assertStringContainsString('<form class="admin-form-card" method="post" action="/admin/users">', (string) $form->getBody());
        self::assertMatchesRegularExpression('~<div class="admin-action-bar-buttons">\s*<button type="submit" class="admin-button admin-button-primary"><svg[^>]*>.*?</svg>Save</button>~s', (string) $form->getBody());

        $response = $this->browser->post('/admin/users', [
            '_csrf' => $this->csrfToken(),
            'email' => 'New@Example.com',
            'name' => 'New',
            'role' => 'author',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/users', $response->getHeaderLine('Location'));
        $created = $this->users()->findByEmail('new@example.com');
        self::assertSame('author', $created?->role);
        self::assertSame(
            ['user_id' => $admin->id, 'ip' => '203.0.113.7'],
            $this->db->selectOne("SELECT user_id, ip FROM audit_log WHERE action = 'user.created'"),
        );
    }

    public function testInvalidInputRerendersFormWithErrorsAndValues(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->post('/admin/users', [
            '_csrf' => $this->csrfToken(),
            'email' => 'bad<script>',
            'name' => 'X',
            'role' => 'ghost',
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Enter a valid email address.', $body);
        self::assertStringContainsString('Choose one of the configured roles.', $body);
        self::assertStringContainsString('value="bad&lt;script&gt;"', $body);
        self::assertSame(1, $this->db->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'ANN@example.com', 'role' => 'admin']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('A user with this email already exists.', (string) $response->getBody());
    }

    public function testAdminChangesRoleAndDisablesUserWhoIsLoggedOutImmediately(): void
    {
        $admin = $this->createUser('ann@example.com');
        $bob = $this->createUser('bob@example.com', 'editor', 'Bob');
        $bobBrowser = new Browser($this->app);
        $this->actingAs($bob, $bobBrowser);
        self::assertSame(200, $bobBrowser->get('/admin')->getStatusCode());
        $this->actingAs($admin);

        $form = (string) $this->browser->get('/admin/users/' . $bob->id)->getBody();
        self::assertStringContainsString('value="bob@example.com" disabled', $form);
        self::assertStringContainsString('<option value="editor" selected>Editor</option>', $form);

        $response = $this->browser->post('/admin/users/' . $bob->id, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Bobby',
            'role' => 'author',
            'status' => 'disabled',
        ]);

        self::assertSame(303, $response->getStatusCode());
        $updated = $this->users()->find($bob->id);
        self::assertSame('Bobby', $updated?->name);
        self::assertSame('author', $updated->role);
        self::assertSame(UserStatus::Disabled, $updated->status);
        self::assertSame(
            '{"email":"bob@example.com","changes":{"name":{"from":"Bob","to":"Bobby"},"role":{"from":"editor","to":"author"},"status":{"from":"active","to":"disabled"}}}',
            $this->db->scalar("SELECT meta FROM audit_log WHERE action = 'user.disabled'"),
        );
        self::assertSame(302, $bobBrowser->get('/admin')->getStatusCode());
    }

    public function testRoleChangeTakesEffectOnNextRequest(): void
    {
        $admin = $this->createUser('ann@example.com');
        $ed = $this->createUser('ed@example.com', 'editor');
        $edBrowser = new Browser($this->app);
        $this->actingAs($ed, $edBrowser);
        self::assertSame(403, $edBrowser->get('/admin/users')->getStatusCode());

        $this->actingAs($admin);
        $this->browser->post('/admin/users/' . $ed->id, ['_csrf' => $this->csrfToken(), 'name' => '', 'role' => 'admin', 'status' => 'active']);

        self::assertSame(200, $edBrowser->get('/admin/users')->getStatusCode());
    }

    public function testAdminCannotChangeOwnRoleOrStatus(): void
    {
        $admin = $this->createUser('ann@example.com');
        $this->actingAs($admin);

        $own = (string) $this->browser->get('/admin/users/' . $admin->id)->getBody();
        self::assertStringContainsString('<select id="role" disabled>', $own);
        self::assertStringContainsString('<input type="hidden" name="role" value="admin">', $own);

        $response = $this->browser->post('/admin/users/' . $admin->id, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Ann',
            'role' => 'editor',
            'status' => 'active',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('admin', $this->users()->find($admin->id)?->role);

        $renamed = $this->browser->post('/admin/users/' . $admin->id, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Ann B',
            'role' => 'admin',
            'status' => 'active',
        ]);
        self::assertSame(303, $renamed->getStatusCode());
        self::assertSame('Ann B', $this->users()->find($admin->id)?->name);
    }

    public function testInvalidStatusIsAValidationError(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        $bob = $this->createUser('bob@example.com', 'editor');

        $response = $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => '', 'role' => 'editor', 'status' => 'banned']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Choose a valid status.', (string) $response->getBody());
    }

    public function testStaleRoleInEditFormDoesNotSilentlyPromote(): void
    {
        $admin = $this->createUser('ann@example.com');
        $stale = $this->users()->create('bob@example.com', 'writer', 'Bob');
        $this->actingAs($admin);

        $form = (string) $this->browser->get('/admin/users/' . $stale->id)->getBody();
        self::assertStringContainsString('<option value="" selected disabled>Unknown role “writer” — choose one</option>', $form);
        self::assertStringNotContainsString('<option value="admin" selected>', $form);
        self::assertStringNotContainsString('<option value="editor" selected>', $form);
        self::assertStringNotContainsString('<option value="author" selected>', $form);

        $response = $this->browser->post('/admin/users/' . $stale->id, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Bobby',
            'status' => 'active',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Choose one of the configured roles.', (string) $response->getBody());
        self::assertSame('writer', $this->users()->find($stale->id)?->role);
    }

    public function testUnknownUserIs404(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        self::assertSame(404, $this->browser->get('/admin/users/999')->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/users/99999999999999999999999')->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/users/abc')->getStatusCode());
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->post('/admin/users', ['email' => 'x@example.com', 'role' => 'admin']);

        self::assertSame(419, $response->getStatusCode());
        self::assertSame(1, $this->db->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testSavingShowsAFlashMessageOnce(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'new@example.com', 'role' => 'author']);
        $list = (string) $this->browser->get('/admin/users')->getBody();
        $again = (string) $this->browser->get('/admin/users')->getBody();

        self::assertMatchesRegularExpression('~<div class="admin-toast admin-toast-success" data-toast><svg[^>]*><use href="[^"]+#check"></use></svg><p class="admin-toast-text">User created\.</p>~', $list);
        self::assertStringNotContainsString('admin-toast admin-toast-', $again, 'the stack is empty the second time');
    }

    public function testAnEmptyRoleKeepsTheChooseARolePlaceholder(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'new@example.com', 'role' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<option value="" selected disabled>Choose a role</option>', (string) $response->getBody());
        self::assertStringNotContainsString('Unknown role', (string) $response->getBody());
    }

    public function testAStaleFormDoesNotReenableAUser(): void
    {
        $admin = $this->createUser('ann@example.com');
        $bob = $this->createUser('bob@example.com', 'editor', 'Bob');
        $this->actingAs($admin);
        self::assertStringContainsString('<input type="hidden" name="original_status" value="active">', (string) $this->browser->get('/admin/users/' . $bob->id)->getBody());
        $this->app->container()->service(\AtarCms\Auth\UserManager::class)->disable($bob, $admin, null);

        $response = $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => 'Bob', 'role' => 'editor', 'status' => 'active', 'original_status' => 'active']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('This user was changed after you opened the form.', (string) $response->getBody());
        self::assertSame(UserStatus::Disabled, $this->users()->find($bob->id)?->status);
    }

    public function testASelfEditIgnoresPostedRoleAndStatus(): void
    {
        $admin = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->actingAs($admin);

        $response = $this->browser->post('/admin/users/' . $admin->id, ['_csrf' => $this->csrfToken(), 'name' => 'Ann B', 'role' => 'author', 'status' => 'disabled', 'original_status' => 'active']);

        self::assertSame(303, $response->getStatusCode());
        $stored = $this->users()->find($admin->id);
        self::assertSame(['Ann B', 'admin', UserStatus::Active], [$stored?->name, $stored?->role, $stored?->status]);
    }

    public function testAnInvalidStatusIsReportedTogetherWithOtherErrors(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        $bob = $this->createUser('bob@example.com', 'editor');

        $body = (string) $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => str_repeat('a', 191), 'role' => 'editor', 'status' => 'bogus', 'original_status' => 'active'])->getBody();

        self::assertStringContainsString('Choose a valid status.', $body);
        self::assertStringContainsString('The name may not be longer than 190 characters.', $body);
    }

    public function testStatusChangesAreAuditedWithTheCliNames(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        $bob = $this->createUser('bob@example.com', 'editor', 'Bob');

        $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => 'Bob', 'role' => 'editor', 'status' => 'disabled', 'original_status' => 'active']);
        $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => 'Bob', 'role' => 'editor', 'status' => 'active', 'original_status' => 'disabled']);
        $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => 'Robert', 'role' => 'editor', 'status' => 'active', 'original_status' => 'active']);

        self::assertSame(['user.disabled', 'user.enabled', 'user.updated'], $this->auditActions());
        // Each row names its user (the activity history shows the e-mail, also after the user is gone).
        foreach ($this->db->select('SELECT meta FROM audit_log ORDER BY id') as $row) {
            $meta = json_decode(Cast::string($row['meta']), true);
            self::assertSame('bob@example.com', is_array($meta) ? ($meta['email'] ?? null) : null);
        }
        $history = (string) $this->browser->get('/admin/audit')->getBody();
        self::assertStringNotContainsString('#' . $bob->id . '<', $history);
        self::assertSame(3, substr_count($history, '“bob@example.com”'));
    }

    public function testEditorsAndAuthorsCannotPostUserChanges(): void
    {
        $bob = $this->createUser('bob@example.com', 'editor');
        foreach (['editor', 'author'] as $role) {
            $this->actingAs($this->createUser($role . '-actor@example.com', $role));

            self::assertSame(403, $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'x@example.com', 'role' => 'author'])->getStatusCode(), $role);
            self::assertSame(403, $this->browser->post('/admin/users/' . $bob->id, ['_csrf' => $this->csrfToken(), 'name' => 'x', 'role' => 'author', 'status' => 'active', 'original_status' => 'active'])->getStatusCode(), $role);
        }
    }

    private function users(): UserRepository
    {
        return $this->app->container()->service(UserRepository::class);
    }
}
