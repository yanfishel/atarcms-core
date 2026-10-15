<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Auth;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\Avatar\Avatars;
use AtarCms\Auth\Roles;
use AtarCms\Auth\User;
use AtarCms\Auth\UserManager;
use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\Locales;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\MediaConfig;
use AtarCms\Settings\AdminAccent;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\FrozenClock;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class UserManagerTest extends DatabaseTestCase
{
    use TempDirectory;

    private UserRepository $users;

    private AuditLog $audit;

    private UserManager $manager;

    private Avatars $avatars;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
        $clock = new FrozenClock();
        $this->users = new UserRepository($this->db, $clock);
        $this->audit = new AuditLog($this->db, $clock);
        $this->avatars = new Avatars($this->tempDir() . '/avatars', new MediaConfig(), new ImageProcessor());
        $this->manager = new UserManager($this->users, new Roles(Roles::DEFAULTS), $this->audit, new Locales('en', ['en', 'ru']), $this->avatars, $this->db);
    }

    public function testSetsAnInterfaceLanguageWithoutAnAuditRow(): void
    {
        $user = $this->manager->create('ann@example.com', 'admin', '', null, null);
        $audited = $this->audit->count();
        // The interface languages are the admin catalogs, whatever the site's locales are.
        $englishSite = new UserManager($this->users, new Roles(Roles::DEFAULTS), $this->audit, new Locales('en', ['en']), $this->avatars, $this->db);

        $englishSite->setLocale($user->id, 'ru');
        self::assertSame('ru', $this->users->find($user->id)?->locale);

        $this->manager->setLocale($user->id, null);
        self::assertNull($this->users->find($user->id)->locale);

        foreach (['it', '', 'RU', 'ru/'] as $invalid) {
            $this->manager->setLocale($user->id, 'en');
            try {
                $this->manager->setLocale($user->id, $invalid);
                self::fail('Expected InvalidArgumentException for "' . $invalid . '"');
            } catch (InvalidArgumentException) {
                self::assertSame('en', $this->users->find($user->id)?->locale, $invalid);
            }
        }
        self::assertSame($audited, $this->audit->count(), 'a personal preference is not audited');
    }

    public function testSavesTheInterfaceLanguageAndTheAdminColourTogetherWithoutAnAuditRow(): void
    {
        $user = $this->manager->create('ann@example.com', 'admin', '', null, null);
        $audited = $this->audit->count();

        $this->manager->savePreferences($user->id, 'he', 'cherry');
        $saved = $this->users->find($user->id);
        self::assertSame(['he', AdminAccent::Cherry], [$saved?->locale, $saved?->accent]);

        $this->manager->savePreferences($user->id, 'ru', '');
        $saved = $this->users->find($user->id);
        self::assertSame(['ru', null], [$saved?->locale, $saved?->accent], 'an empty colour follows the site colour');
        self::assertSame($audited, $this->audit->count(), 'personal preferences are not audited');
    }

    public function testRefusedPreferencesStoreNothing(): void
    {
        $user = $this->manager->create('ann@example.com', 'admin', '', null, null);
        $this->manager->savePreferences($user->id, 'en', 'teal');

        foreach ([
            ['it', 'teal', ['locale' => 'validation.locale.interface']],
            ['', 'teal', ['locale' => 'validation.locale.interface']],
            ['ru', 'pink', ['accent' => 'validation.profile.accent']],
            ['ru', 'Teal', ['accent' => 'validation.profile.accent']],
            ['xx', 'pink', ['locale' => 'validation.locale.interface', 'accent' => 'validation.profile.accent']],
        ] as [$locale, $accent, $errors]) {
            try {
                $this->manager->savePreferences($user->id, $locale, $accent);
                self::fail('Expected ValidationFailed for ' . $locale . '/' . $accent);
            } catch (ValidationFailed $e) {
                self::assertSame($errors, $e->errors, $locale . '/' . $accent);
            }
            $saved = $this->users->find($user->id);
            self::assertSame(['en', AdminAccent::Teal], [$saved?->locale, $saved?->accent], 'nothing stored');
        }
    }

    public function testSetsAContentLanguageOfTheSiteWithoutAnAuditRow(): void
    {
        $user = $this->manager->create('ann@example.com', 'admin', '', null, null);
        $audited = $this->audit->count();
        self::assertNull($this->users->find($user->id)?->contentLocale);

        $this->manager->setContentLocale($user->id, 'ru');
        self::assertSame('ru', $this->users->find($user->id)?->contentLocale);
        self::assertNull($this->users->find($user->id)->locale, 'the interface language is separate');

        foreach (['it', '', 'RU', 'ru/'] as $invalid) {
            try {
                $this->manager->setContentLocale($user->id, $invalid);
                self::fail('Expected InvalidArgumentException for "' . $invalid . '"');
            } catch (InvalidArgumentException) {
                self::assertSame('ru', $this->users->find($user->id)?->contentLocale, $invalid);
            }
        }
        self::assertSame($audited, $this->audit->count(), 'a personal preference is not audited');
    }

    public function testCreatesAndAuditsUsers(): void
    {
        $user = $this->manager->create(' Ann@Example.com ', 'editor', 'Ann', null, '203.0.113.7');

        self::assertSame('ann@example.com', $user->email);
        $entry = $this->audit->latest(1)[0];
        self::assertSame('user.created', $entry->action);
        self::assertNull($entry->userId);
        self::assertSame((string) $user->id, $entry->subjectId);
        self::assertSame(['email' => 'ann@example.com', 'role' => 'editor'], $entry->meta);
    }

    public function testValidatesEmailRoleAndName(): void
    {
        try {
            $this->manager->create('not-an-email', 'ghost', str_repeat('x', 191), null, null);
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['name', 'role', 'email'], array_keys($e->errors));
        }
        self::assertSame(0, $this->db->scalar('SELECT COUNT(*) FROM users'));
        self::assertSame(0, $this->audit->count());
    }

    public function testRejectsDuplicateEmail(): void
    {
        $this->manager->create('ann@example.com', 'admin', '', null, null);

        try {
            $this->manager->create('ANN@example.com', 'admin', '', null, null);
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['email' => 'validation.email.taken'], $e->errors);
        }
    }

    public function testDisableAuditsOnce(): void
    {
        $user = $this->manager->create('ann@example.com', 'admin', '', null, null);

        $this->manager->disable($user, null, null);
        $disabled = $this->users->find($user->id);
        self::assertNotNull($disabled);
        $this->manager->disable($disabled, null, null);

        self::assertSame(UserStatus::Disabled, $disabled->status);
        self::assertSame(2, $this->audit->count());
        self::assertSame('user.disabled', $this->audit->latest(1)[0]->action);
    }

    public function testUpdateAuditsOnlyRealChanges(): void
    {
        $actor = $this->manager->create('admin@example.com', 'admin', '', null, null);
        $bob = $this->manager->create('bob@example.com', 'editor', 'Bob', null, null);

        $updated = $this->manager->update($bob, 'Bobby', 'author', UserStatus::Disabled, $actor, '203.0.113.7');
        $this->manager->update($updated, 'Bobby', 'author', UserStatus::Disabled, $actor, null);

        self::assertSame('author', $updated->role);
        self::assertSame(UserStatus::Disabled, $updated->status);
        self::assertSame(3, $this->audit->count());
        $entry = $this->audit->latest(1)[0];
        self::assertSame('user.disabled', $entry->action);
        self::assertSame($actor->id, $entry->userId);
        self::assertSame(['email' => 'bob@example.com', 'changes' => [
            'name' => ['from' => 'Bob', 'to' => 'Bobby'],
            'role' => ['from' => 'editor', 'to' => 'author'],
            'status' => ['from' => 'active', 'to' => 'disabled'],
        ]], $entry->meta);
    }

    public function testActorCannotChangeOwnRoleOrStatus(): void
    {
        $actor = $this->manager->create('admin@example.com', 'admin', '', null, null);

        try {
            $this->manager->update($actor, '', 'editor', UserStatus::Disabled, $actor, null);
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['role', 'status'], array_keys($e->errors));
        }

        $renamed = $this->manager->update($actor, 'Ann', 'admin', UserStatus::Active, $actor, null);
        self::assertSame('Ann', $renamed->name);
        self::assertSame('admin', $renamed->role);
    }

    public function testActorCannotAssignARoleTheirOwnRoleDoesNotGrant(): void
    {
        $manager = new UserManager($this->users, new Roles([
            ...Roles::DEFAULTS,
            'manager' => ['label' => 'Manager', 'permissions' => ['users.manage', 'entries.*.*', 'media.*', 'taxonomies.manage', 'menus.manage']],
        ]), $this->audit, new Locales('en', ['en', 'ru']), $this->avatars, $this->db);
        $actor = $this->users->create('manager@example.com', 'manager', '');
        $editor = $this->users->create('ed@example.com', 'editor', 'Ed');

        try {
            $manager->update($editor, 'Ed', 'admin', UserStatus::Active, $actor, null);
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['role' => 'validation.role.not_allowed'], $e->errors);
        }
        self::assertSame('editor', $this->users->find($editor->id)?->role);
        self::assertSame(0, $this->audit->count());
    }

    public function testDeletesAUserAuditsItAndRemovesTheirPhoto(): void
    {
        $actor = $this->users->create('admin@example.com', 'admin');
        $bob = $this->users->create('bob@example.com', 'editor', 'Bob');
        $this->avatars->save($bob->id, TestImages::png(120, 120));
        $this->avatars->save($actor->id, TestImages::png(120, 120));

        $this->manager->delete($bob, $actor, '203.0.113.7');

        self::assertNull($this->users->find($bob->id));
        self::assertFileDoesNotExist($this->avatars->path($bob->id));
        self::assertFileExists($this->avatars->path($actor->id));
        $entry = $this->audit->latest(1)[0];
        self::assertSame('user.deleted', $entry->action);
        self::assertSame($actor->id, $entry->userId);
        self::assertSame('user', $entry->subjectType);
        self::assertSame((string) $bob->id, $entry->subjectId);
        self::assertSame(['email' => 'bob@example.com', 'name' => 'Bob', 'role' => 'editor'], $entry->meta);
        self::assertSame('203.0.113.7', $entry->ip);
    }

    public function testAFailedAuditRowKeepsTheUserAndTheirPhoto(): void
    {
        $actor = $this->users->create('admin@example.com', 'admin');
        $bob = $this->users->create('bob@example.com', 'editor', 'Bob');
        $this->avatars->save($bob->id, TestImages::png(120, 120));
        $this->db->execute('DROP TABLE audit_log');

        // PDOException is not among delete()'s documented exceptions, so it is caught as any Throwable.
        $failure = null;
        try {
            $this->manager->delete($bob, $actor, null);
        } catch (Throwable $e) {
            $failure = $e;
        }
        self::assertInstanceOf(PDOException::class, $failure, 'Expected the audit insert to fail');

        self::assertNotNull($this->users->find($bob->id));
        self::assertFileExists($this->avatars->path($bob->id));
    }

    public function testDeletesAUserWithoutAPhoto(): void
    {
        $actor = $this->users->create('admin@example.com', 'admin');
        $bob = $this->users->create('bob@example.com', 'editor');

        $this->manager->delete($bob, $actor, null);

        self::assertNull($this->users->find($bob->id));
    }

    public function testNobodyDeletesThemselves(): void
    {
        $actor = $this->users->create('admin@example.com', 'admin');

        try {
            $this->manager->delete($actor, $actor, null);
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['user' => 'validation.user.self_delete'], $e->errors);
        }
        self::assertNotNull($this->users->find($actor->id));
        self::assertSame(0, $this->audit->count());
    }

    public function testAUserWithARoleTheActorCannotGrantIsNotDeleted(): void
    {
        [$manager, $actor] = $this->managerRole();
        $admin = $this->users->create('admin@example.com', 'admin');

        try {
            $manager->delete($admin, $actor, null);
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['user' => 'validation.role.not_allowed'], $e->errors);
        }
        self::assertNotNull($this->users->find($admin->id));
        self::assertSame(0, $this->audit->count());
    }

    public function testAUserWithAStaleRoleCanBeDeleted(): void
    {
        [$manager, $actor] = $this->managerRole();
        $ghost = $this->users->create('ghost@example.com', 'ghost');

        $manager->delete($ghost, $actor, null);

        self::assertNull($this->users->find($ghost->id));
    }

    public function testWhoCanBeManagedAndDeleted(): void
    {
        [$manager, $actor] = $this->managerRole();
        $editor = $this->users->create('ed@example.com', 'editor');
        $admin = $this->users->create('admin@example.com', 'admin');
        $ghost = $this->users->create('ghost@example.com', 'ghost');

        foreach ([[$actor, true, false], [$editor, true, true], [$admin, false, false], [$ghost, true, true]] as [$user, $manage, $delete]) {
            self::assertSame($manage, $manager->canManage($actor, $user), $user->email);
            self::assertSame($delete, $manager->canDelete($actor, $user), $user->email);
        }
    }

    /**
     * A manager may grant every default role but admin.
     *
     * @return array{UserManager, User}
     */
    private function managerRole(): array
    {
        $manager = new UserManager($this->users, new Roles([
            ...Roles::DEFAULTS,
            'manager' => ['label' => 'Manager', 'permissions' => ['users.manage', 'entries.*.*', 'media.*', 'taxonomies.manage', 'menus.manage']],
        ]), $this->audit, new Locales('en', ['en', 'ru']), $this->avatars, $this->db);

        return [$manager, $this->users->create('manager@example.com', 'manager')];
    }
}
