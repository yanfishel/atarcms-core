<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\Roles;
use AtarCms\Auth\User;
use AtarCms\Auth\UserStatus;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Seo;
use AtarCms\Http\Exception\Forbidden;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EntryAccessTest extends TestCase
{
    private Collection $news;

    private EntryAccess $access;

    protected function setUp(): void
    {
        $this->news = Collection::make('news');
        $this->access = new EntryAccess(new Authorizer(new Roles([
            ...Roles::DEFAULTS,
            'reader' => ['label' => 'Reader', 'permissions' => ['entries.news.view']],
            'remover' => ['label' => 'Remover', 'permissions' => ['entries.news.view', 'entries.news.delete', 'entries.news.edit_others']],
        ])));
    }

    public function testCollectionPermissions(): void
    {
        $editor = self::user(1, 'editor');
        $author = self::user(2, 'author');
        $reader = self::user(3, 'reader');

        self::assertTrue($this->access->canView($reader, $this->news));
        self::assertFalse($this->access->canCreate($reader, $this->news));
        self::assertTrue($this->access->canCreate($author, $this->news));
        self::assertFalse($this->access->canPublish($author, $this->news));
        self::assertTrue($this->access->canPublish($editor, $this->news));
        self::assertFalse($this->access->canView(null, $this->news));
        self::assertFalse($this->access->canView(self::user(4, 'admin', UserStatus::Disabled), $this->news));
    }

    public function testAuthorsEditAndDeleteOnlyTheirOwnDrafts(): void
    {
        $author = self::user(2, 'author');
        $own = $this->entry(authorId: 2);
        $ownPublished = $this->entry(authorId: 2, status: EntryStatus::Published);
        $others = $this->entry(authorId: 9);
        $orphan = $this->entry(authorId: null);

        self::assertTrue($this->access->canEdit($author, $own));
        self::assertFalse($this->access->canEdit($author, $ownPublished));
        self::assertFalse($this->access->canEdit($author, $others));
        self::assertFalse($this->access->canEdit($author, $orphan));
        self::assertFalse($this->access->canDelete($author, $own), 'the default author role has no delete permission');
    }

    public function testEditorsEditEveryEntry(): void
    {
        $editor = self::user(1, 'editor');

        self::assertTrue($this->access->canEdit($editor, $this->entry(authorId: 9, status: EntryStatus::Scheduled)));
        self::assertTrue($this->access->canDelete($editor, $this->entry(authorId: null)));
        self::assertTrue($this->access->canEdit(self::user(5, 'admin'), $this->entry(authorId: 9, status: EntryStatus::Published)));
        self::assertFalse($this->access->canEdit(self::user(3, 'reader'), $this->entry(authorId: 3)));
    }

    public function testDeletingALiveEntryNeedsThePublishPermission(): void
    {
        $remover = self::user(6, 'remover');

        self::assertTrue($this->access->canDelete($remover, $this->entry(authorId: 9)));
        self::assertFalse($this->access->canDelete($remover, $this->entry(authorId: 9, status: EntryStatus::Published)));
        self::assertFalse($this->access->canDelete($remover, $this->entry(authorId: 9, status: EntryStatus::Scheduled)));
        self::assertTrue($this->access->canDelete(self::user(1, 'editor'), $this->entry(authorId: 9, status: EntryStatus::Published)));
    }

    public function testTheEntryOfASingleIsNeverDeleted(): void
    {
        $now = new DateTimeImmutable('2026-09-28 12:00:00');
        $home = new Entry(1, Collection::make('home')->route('/')->single(), 'en', str_repeat('a', 32), 'home', 'Home', EntryStatus::Published, $now, null, 0, null, $now, $now, new Seo(), []);

        self::assertFalse($this->access->canDelete(self::user(5, 'admin'), $home), 'a fixed page of the site');
    }

    public function testViewableCollectionsAndAuthorize(): void
    {
        $collections = new Collections([$this->news, Collection::make('pages')]);

        self::assertSame(['news'], array_map(static fn(Collection $c): string => $c->name, $this->access->viewable(self::user(3, 'reader'), $collections)));
        self::assertCount(2, $this->access->viewable(self::user(1, 'editor'), $collections));

        $this->access->authorize(true, 'anything');
        $this->expectException(Forbidden::class);
        $this->access->authorize(false, 'edit news #1');
    }

    public function testReorderingNeedsViewAndEditOnTheCollection(): void
    {
        self::assertTrue($this->access->canReorder(self::user(1, 'editor'), $this->news));
        self::assertTrue($this->access->canReorder(self::user(2, 'author'), $this->news));
        self::assertFalse($this->access->canReorder(self::user(3, 'reader'), $this->news));
        self::assertFalse($this->access->canReorder(null, $this->news));
    }

    /**
     * A plain single's or a preset page's translation is published at once: translating them takes the publish
     * permission, also from a draft left over from before.
     */
    public function testTranslatingAPagePublishedAtOnceNeedsThePublishPermission(): void
    {
        $author = self::user(2, 'author');
        $editor = self::user(1, 'editor');
        $now = new DateTimeImmutable('2026-09-28 12:00:00');
        $draft = static fn(Collection $collection, ?string $preset = null): Entry => new Entry(1, $collection, 'en', str_repeat('a', 32), 'x', 'X', EntryStatus::Draft, null, null, 0, 2, $now, $now, new Seo(), [], preset: $preset);
        $plain = Collection::make('news')->route('/news')->single()->plain();
        $pages = Collection::make('news')->route('/{slug}')->presets(['privacy' => 'Privacy']);

        self::assertTrue($this->access->canTranslate($author, $this->entry(authorId: 2)), 'an ordinary draft');
        self::assertFalse($this->access->canTranslate($author, $draft($plain)));
        self::assertFalse($this->access->canTranslate($author, $draft($pages, 'privacy')));
        self::assertTrue($this->access->canTranslate($author, $draft($pages)), 'an ordinary page of that collection');
        self::assertTrue($this->access->canTranslate($editor, $draft($plain)));
        self::assertTrue($this->access->canTranslate($editor, $draft($pages, 'privacy')));
        self::assertFalse($this->access->canTranslate(self::user(3, 'reader'), $this->entry(authorId: 3)));
    }

    private function entry(?int $authorId, EntryStatus $status = EntryStatus::Draft): Entry
    {
        $now = new DateTimeImmutable('2026-09-28 12:00:00');

        return new Entry(1, $this->news, 'en', str_repeat('a', 32), 'x', 'X', $status, $now, null, 0, $authorId, $now, $now, new Seo(), []);
    }

    private static function user(int $id, string $role, UserStatus $status = UserStatus::Active): User
    {
        return new User($id, $role . $id . '@example.com', '', $role, null, $status, null, null, null, new DateTimeImmutable());
    }
}
