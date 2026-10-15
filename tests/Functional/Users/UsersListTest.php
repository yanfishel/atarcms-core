<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Users;

use AtarCms\Auth\Roles;
use AtarCms\Auth\UserRepository;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\FunctionalTestCase;

/**
 * The users list: the last sign-in as the shared table date, "Add user" and each row's Edit and Delete opening the
 * screen's dialog, and the locked actions (one's own Delete, a user with a role one cannot grant).
 */
final class UsersListTest extends FunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        // A manager may grant every default role but admin.
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'manager' => ['label' => 'Manager', 'permissions' => ['users.manage', 'entries.*.*', 'media.*', 'taxonomies.manage', 'menus.manage']],
        ]));
    }

    public function testTheLastSignInIsATableDateAndNeverWithoutOne(): void
    {
        $admin = $this->createUser('ann@example.com', 'admin', 'Ann');
        $bob = $this->createUser('bob@example.com', 'editor', 'Bob');
        $this->app->container()->service(UserRepository::class)->recordLogin($bob->id);
        $this->actingAs($admin);

        $body = (string) $this->browser->get('/admin/users')->getBody();

        self::assertMatchesRegularExpression('~<td data-label="Last sign-in"><span class="admin-table-date">[^<]+</span><span class="admin-table-time">\d\d:\d\d</span></td>~', $body);
        self::assertStringContainsString('<td data-label="Last sign-in"><span class="admin-muted">never</span></td>', $body);
    }

    public function testTheRoleChipsAndTheTopBarAvatarCarryTheRoleColour(): void
    {
        $admin = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->createUser('bob@example.com', 'editor', 'Bob');
        $this->createUser('max@example.com', 'manager', 'Max');
        $this->actingAs($admin);

        $body = (string) $this->browser->get('/admin/users')->getBody();

        self::assertStringContainsString('<span class="admin-chip admin-role admin-role-admin">Administrator</span>', $body);
        self::assertStringContainsString('<span class="admin-chip admin-role admin-role-editor">Editor</span>', $body);
        self::assertStringContainsString('<span class="admin-chip admin-role admin-role-manager">Manager</span>', $body);
        self::assertStringContainsString('<span class="admin-avatar admin-role admin-role-admin" aria-hidden="true">', $body);
    }

    public function testAddUserAndTheRowsOpenTheDialog(): void
    {
        $admin = $this->createUser('ann@example.com', 'admin', 'Ann');
        $bob = $this->createUser('bob<b>@example.com', 'editor', 'Bob');
        $this->actingAs($admin);

        $body = (string) $this->browser->get('/admin/users')->getBody();

        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-primary" href="/admin/users/new" data-dialog-open><svg[^>]*><use href="[^"]+#plus"></use></svg>Add user</a>~', $body);
        self::assertMatchesRegularExpression('~<script type="module" src="[^"]*dialog\.js[^"]*"~', $body);
        self::assertMatchesRegularExpression('~<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">\s*<div class="admin-dialog-content" data-dialog-content></div>\s*</dialog>~', $body);
        self::assertStringContainsString('<th><span class="admin-visually-hidden">Actions</span></th>', $body);
        self::assertStringContainsString('<a class="admin-row-link" href="/admin/users/' . $bob->id . '" data-dialog-open>bob&lt;b&gt;@example.com</a>', $body);
        self::assertMatchesRegularExpression(
            '~<td class="admin-table-actions"><span class="admin-row-actions">\s*'
            . '<a class="admin-row-action" href="/admin/users/' . $bob->id . '" title="Edit “bob&lt;b&gt;@example.com”" aria-label="Edit “bob&lt;b&gt;@example.com”" data-dialog-open><svg[^>]*><use href="[^"]+#pencil"></use></svg></a>\s*'
            . '<a class="admin-row-action admin-row-action-danger" href="/admin/users/' . $bob->id . '/delete" title="Delete “bob&lt;b&gt;@example.com”" aria-label="Delete “bob&lt;b&gt;@example.com”" data-dialog-open><svg[^>]*><use href="[^"]+#trash-2"></use></svg></a>\s*'
            . '</span></td>~',
            $body,
        );
    }

    public function testOnesOwnDeleteIsLocked(): void
    {
        $admin = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->actingAs($admin);

        $body = (string) $this->browser->get('/admin/users')->getBody();
        $reason = 'You cannot delete your own account\.';

        self::assertStringContainsString('<a class="admin-row-link" href="/admin/users/' . $admin->id . '" data-dialog-open>ann@example.com</a>', $body);
        self::assertMatchesRegularExpression(
            '~<a class="admin-row-action" href="/admin/users/' . $admin->id . '" [^>]*data-dialog-open>.*?</a>\s*'
            . '<button type="button" class="admin-row-action admin-row-action-danger admin-locked" aria-disabled="true" title="' . $reason . '" aria-label="Delete “ann@example.com”" aria-describedby="user-' . $admin->id . '-delete-reason"><svg[^>]*><use href="[^"]+#trash-2"></use></svg></button><span class="admin-visually-hidden" id="user-' . $admin->id . '-delete-reason">' . $reason . '</span>~s',
            $body,
        );
        self::assertStringNotContainsString('/admin/users/' . $admin->id . '/delete', $body);
    }

    public function testAUserWithARoleOneCannotGrantIsLockedAndPlainText(): void
    {
        $manager = $this->createUser('manager@example.com', 'manager');
        $admin = $this->createUser('ann@example.com', 'admin');
        $editor = $this->createUser('ed@example.com', 'editor');
        $this->actingAs($manager);

        $body = (string) $this->browser->get('/admin/users')->getBody();
        $reason = 'You cannot manage a user with this role\.';

        self::assertStringContainsString('<td data-label="Email"><span class="admin-row-title">ann@example.com</span></td>', $body);
        self::assertMatchesRegularExpression(
            '~<span class="admin-row-actions">\s*'
            . '<button type="button" class="admin-row-action admin-locked" aria-disabled="true" title="' . $reason . '" aria-label="Edit “ann@example.com”" aria-describedby="user-' . $admin->id . '-edit-reason"><svg[^>]*><use href="[^"]+#pencil"></use></svg></button><span class="admin-visually-hidden" id="user-' . $admin->id . '-edit-reason">' . $reason . '</span>\s*'
            . '<button type="button" class="admin-row-action admin-row-action-danger admin-locked" aria-disabled="true" title="' . $reason . '" aria-label="Delete “ann@example.com”" aria-describedby="user-' . $admin->id . '-delete-reason"><svg[^>]*><use href="[^"]+#trash-2"></use></svg></button><span class="admin-visually-hidden" id="user-' . $admin->id . '-delete-reason">' . $reason . '</span>\s*'
            . '</span>~',
            $body,
        );
        self::assertStringContainsString('href="/admin/users/' . $editor->id . '/delete"', $body);
    }
}
