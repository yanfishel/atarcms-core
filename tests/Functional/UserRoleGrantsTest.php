<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\Roles;
use AtarCms\Auth\UserRepository;
use AtarCms\Kernel\Container;

final class UserRoleGrantsTest extends FunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'manager' => ['label' => 'Manager', 'permissions' => ['users.manage', 'entries.*.*', 'media.*', 'taxonomies.manage', 'menus.manage']],
        ]));
    }

    public function testManagersAssignOnlyRolesTheirOwnRoleCovers(): void
    {
        $admin = $this->createUser('root@example.com', 'admin');
        $this->actingAs($this->createUser('manager@example.com', 'manager'));

        $form = (string) $this->browser->get('/admin/users/new')->getBody();
        self::assertStringContainsString('<option value="editor">Editor</option>', $form);
        self::assertStringNotContainsString('value="admin"', $form);

        self::assertSame(303, $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'ed@example.com', 'role' => 'editor'])->getStatusCode());
        $forged = $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'boss@example.com', 'role' => 'admin']);
        self::assertSame(422, $forged->getStatusCode());
        self::assertStringContainsString('You cannot assign this role.', (string) $forged->getBody());
        self::assertNull($this->users()->findByEmail('boss@example.com'));

        self::assertSame(403, $this->browser->get('/admin/users/' . $admin->id)->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/users/' . $admin->id, ['_csrf' => $this->csrfToken(), 'name' => 'x', 'role' => 'author', 'status' => 'disabled', 'original_status' => 'active'])->getStatusCode());
    }

    public function testUsersIndexLinksOnlyToManageableUsers(): void
    {
        $admin = $this->createUser('root@example.com', 'admin');
        $manager = $this->createUser('manager@example.com', 'manager', 'Manager');
        $this->actingAs($manager);

        $body = (string) $this->browser->get('/admin/users')->getBody();

        self::assertStringNotContainsString('href="/admin/users/' . $admin->id . '"', $body);
        self::assertStringContainsString('>root@example.com<', $body);
        self::assertStringContainsString('href="/admin/users/' . $manager->id . '"', $body);
    }

    public function testManagersCannotEscalateAnExistingUsersRoleViaUpdate(): void
    {
        $editor = $this->createUser('ed@example.com', 'editor', 'Ed');
        $this->actingAs($this->createUser('manager@example.com', 'manager'));

        $response = $this->browser->post('/admin/users/' . $editor->id, [
            '_csrf' => $this->csrfToken(),
            'name' => 'Ed',
            'role' => 'admin',
            'status' => 'active',
            'original_status' => 'active',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('You cannot assign this role.', (string) $response->getBody());
        self::assertSame('editor', $this->users()->find($editor->id)?->role);
    }

    private function users(): UserRepository
    {
        return $this->app->container()->service(UserRepository::class);
    }
}
