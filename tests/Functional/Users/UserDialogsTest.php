<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Users;

use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Tests\Functional\FunctionalTestCase;

/**
 * Requested by htmx (HX-Request), the new and edit pages of a user answer with the dialog's fragment. Field errors
 * come back into the dialog (200: htmx swaps no error response); a save sends the browser to the users list with the
 * flash as a toast. Without the header the same URLs are the full pages.
 */
final class UserDialogsTest extends FunctionalTestCase
{
    private const array HTMX = ['HX-Request' => 'true'];

    public function testTheDialogCreatesAUser(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $new = (string) $this->browser->get('/admin/users/new', self::HTMX)->getBody();
        self::assertStringNotContainsString('<html', $new);
        self::assertStringContainsString('<h2 id="dialog-title">Add user</h2>', $new);
        self::assertStringContainsString('<form class="admin-dialog-form" method="post" action="/admin/users" hx-post="/admin/users" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">', $new);
        self::assertStringContainsString('<input id="email" name="email" type="email" required maxlength="254" dir="ltr" value="">', $new);
        self::assertStringContainsString('<button type="button" class="admin-button admin-button-secondary" data-dialog-close>Cancel</button>', $new);
        self::assertStringNotContainsString('id="status"', $new, 'a new user is active');

        $refused = $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'bad<b>', 'name' => 'New', 'role' => 'author'], self::HTMX);
        self::assertSame(200, $refused->getStatusCode());
        self::assertSame('', $refused->getHeaderLine('HX-Redirect'));
        $body = (string) $refused->getBody();
        self::assertStringNotContainsString('<html', $body);
        self::assertStringContainsString('<div class="admin-alert" role="alert">', $body);
        self::assertStringContainsString('<div class="admin-field admin-field-invalid">', $body);
        self::assertStringContainsString('Enter a valid email address.', $body);
        self::assertStringContainsString('value="bad&lt;b&gt;"', $body);
        self::assertStringContainsString('<option value="author" selected>Author</option>', $body);

        $created = $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'new@example.com', 'name' => 'New', 'role' => 'author'], self::HTMX);
        self::assertSame(200, $created->getStatusCode());
        self::assertSame('/admin/users', $created->getHeaderLine('HX-Redirect'));
        self::assertSame('author', $this->users()->findByEmail('new@example.com')?->role);
        self::assertMatchesRegularExpression('~admin-toast-success.*User created\.~s', (string) $this->browser->get('/admin/users')->getBody());
    }

    public function testTheDialogEditsAUser(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        $bob = $this->createUser('bob@example.com', 'editor', 'Bob');

        $edit = (string) $this->browser->get('/admin/users/' . $bob->id, self::HTMX)->getBody();
        self::assertStringNotContainsString('<html', $edit);
        self::assertStringContainsString('<h2 id="dialog-title">bob@example.com</h2>', $edit);
        self::assertStringContainsString('hx-post="/admin/users/' . $bob->id . '"', $edit);
        self::assertStringContainsString('<input type="hidden" name="original_status" value="active">', $edit);
        self::assertStringContainsString('<option value="editor" selected>Editor</option>', $edit);
        self::assertStringContainsString('<select id="status" name="status">', $edit);

        $post = ['_csrf' => $this->csrfToken(), 'name' => 'Bobby', 'role' => 'author', 'status' => 'active'];
        $stale = $this->browser->post('/admin/users/' . $bob->id, [...$post, 'original_status' => 'disabled'], self::HTMX);
        self::assertSame(200, $stale->getStatusCode());
        self::assertSame('', $stale->getHeaderLine('HX-Redirect'), 'the stale status is a field error, in the dialog');
        self::assertStringContainsString('This user was changed after you opened the form. Reload the page.', (string) $stale->getBody());

        $saved = $this->browser->post('/admin/users/' . $bob->id, [...$post, 'original_status' => 'active'], self::HTMX);
        self::assertSame('/admin/users', $saved->getHeaderLine('HX-Redirect'));
        self::assertSame('author', $this->users()->find($bob->id)?->role);
        self::assertSame('Bobby', $this->users()->find($bob->id)->name);
        self::assertMatchesRegularExpression('~admin-toast-success.*Changes saved\.~s', (string) $this->browser->get('/admin/users')->getBody());
    }

    public function testEditingOneselfInTheDialogKeepsRoleAndStatus(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->actingAs($ann);

        $edit = (string) $this->browser->get('/admin/users/' . $ann->id, self::HTMX)->getBody();
        self::assertStringContainsString('<input type="hidden" name="role" value="admin">', $edit);
        self::assertStringContainsString('<select id="role" disabled>', $edit);
        self::assertStringContainsString('<select id="status" disabled>', $edit);

        $saved = $this->browser->post('/admin/users/' . $ann->id, ['_csrf' => $this->csrfToken(), 'name' => 'Annie', 'role' => 'author', 'status' => 'disabled', 'original_status' => 'active'], self::HTMX);
        self::assertSame('/admin/users', $saved->getHeaderLine('HX-Redirect'));
        $self = $this->users()->find($ann->id);
        self::assertSame(['Annie', 'admin', UserStatus::Active], [$self?->name, $self?->role, $self?->status]);
    }

    public function testWithoutTheHeaderTheSameUrlsAreFullPages(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));
        $bob = $this->createUser('bob@example.com', 'editor');

        foreach (['/admin/users/new', '/admin/users/' . $bob->id] as $url) {
            $page = (string) $this->browser->get($url)->getBody();
            self::assertStringContainsString('<html', $page, $url);
            self::assertStringContainsString('<form class="admin-form-card" method="post"', $page, $url);
            self::assertStringNotContainsString('admin-dialog-form', $page, $url);
        }
        $refused = $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'bad', 'role' => 'author']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('<html', (string) $refused->getBody());
    }

    private function users(): UserRepository
    {
        return $this->app->container()->service(UserRepository::class);
    }
}
