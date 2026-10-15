<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth;

use AtarCms\Auth\Roles;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    public function testDefaultRoles(): void
    {
        $roles = Roles::fromConfig(new Config());

        self::assertSame(['admin' => 'admin.roles.admin', 'editor' => 'admin.roles.editor', 'author' => 'admin.roles.author'], $roles->labels(), 'translation keys, shown through the label() helper');
        self::assertTrue($roles->allows('admin', 'users.manage'));
        self::assertTrue($roles->allows('editor', 'entries.news.publish'));
        self::assertTrue($roles->allows('editor', 'media.delete'));
        self::assertFalse($roles->allows('editor', 'users.manage'));
        self::assertFalse($roles->allows('editor', 'audit.view'));
        self::assertTrue($roles->allows('author', 'entries.news.edit'));
        self::assertFalse($roles->allows('author', 'entries.news.publish'));
        self::assertFalse($roles->allows('author', 'media.delete'));
        self::assertFalse($roles->allows('ghost', 'entries.news.view'));
        self::assertTrue($roles->exists('author'));
        self::assertFalse($roles->exists('ghost'));
        self::assertSame('ghost', $roles->label('ghost'));
    }

    public function testConfiguredRolesReplaceDefaults(): void
    {
        $roles = Roles::fromConfig(new Config(['roles' => [
            'chief' => ['label' => 'Chief editor', 'permissions' => ['entries.*.*', 'audit.view']],
        ]]));

        self::assertSame(['chief' => 'Chief editor'], $roles->labels());
        self::assertTrue($roles->allows('chief', 'audit.view'));
        self::assertFalse($roles->exists('admin'));
    }

    public function testRejectsInvalidPermission(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('entries..view');

        new Roles(['x' => ['label' => 'X', 'permissions' => ['entries..view']]]);
    }

    public function testRejectsMissingPermissionsList(): void
    {
        $this->expectException(ConfigException::class);

        new Roles(['x' => ['label' => 'X']]);
    }

    public function testRejectsInvalidRoleName(): void
    {
        $this->expectException(ConfigException::class);

        new Roles(['Bad Role' => ['permissions' => ['*']]]);
    }

    public function testGrantsRolesWhosePermissionsAreCovered(): void
    {
        $roles = new Roles([
            ...Roles::DEFAULTS,
            'manager' => ['label' => 'Manager', 'permissions' => ['users.manage', 'entries.*.*', 'media.*', 'taxonomies.manage', 'menus.manage']],
        ]);

        self::assertTrue($roles->grants('admin', 'admin'));
        self::assertTrue($roles->grants('manager', 'editor'));
        self::assertTrue($roles->grants('manager', 'author'));
        self::assertTrue($roles->grants('manager', 'manager'));
        self::assertFalse($roles->grants('manager', 'admin'));
        self::assertFalse($roles->grants('editor', 'manager'));
        self::assertFalse($roles->grants('author', 'editor'));
        self::assertFalse($roles->grants('admin', 'ghost'));
    }
}
