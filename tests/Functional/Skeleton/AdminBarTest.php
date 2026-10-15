<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Term;
use AtarCms\Content\TermInput;
use AtarCms\Content\TermService;
use AtarCms\Database\Connection;
use AtarCms\Messages\MessageRepository;
use AtarCms\Tests\Support\QueryCounter;
use DateTimeImmutable;

/**
 * The quick-actions bar of signed-in users on the public site (theme helper adminBar(), partials/admin-bar.php).
 */
final class AdminBarTest extends SkeletonTestCase
{
    private Term $section;

    private Entry $article;

    protected function setUp(): void
    {
        parent::setUp();
        $container = $this->app->container();
        $sections = $container->service(Taxonomies::class)->get('sections');
        $this->section = $container->service(TermService::class)->create($sections, 'en', new TermInput('Services', 'services'), null, null);
        $this->article = $container->service(EntryService::class)->create(
            $container->service(Collections::class)->get('articles'),
            'en',
            new EntryInput('Hello', 'hello', EntryStatus::Published, new DateTimeImmutable('-1 hour'), terms: ['sections' => [$this->section->id]]),
            null,
            null,
        );
    }

    public function testVisitorsGetNoBarAndNoQueryForIt(): void
    {
        $body = (string) $this->browser->get('/services/hello')->getBody();

        self::assertStringNotContainsString('admin-bar', $body);
        $db = $this->app->container()->service(Connection::class);
        $anonymous = QueryCounter::measure($db, fn(): mixed => $this->browser->get('/services/hello'));
        $this->actingAs($this->createUser('editor@example.com', 'editor'));
        $signedIn = QueryCounter::measure($db, fn(): mixed => $this->browser->get('/services/hello'));
        self::assertGreaterThan($anonymous, $signedIn, 'only a signed-in user costs queries');
    }

    public function testAnEditorOnAnArticleGetsTheDashboardAddAndEdit(): void
    {
        $this->actingAs($this->createUser('editor@example.com', 'editor'));

        $body = (string) $this->browser->get('/services/hello')->getBody();

        self::assertMatchesRegularExpression('~<nav class="admin-bar" aria-label="Site management">\s*<ul>~', $body);
        self::assertStringContainsString('<a class="admin-bar-action admin-bar-dashboard" href="/admin" aria-label="Admin panel" title="Admin panel">', $body);
        self::assertStringContainsString('<a class="admin-bar-action admin-bar-add" href="/admin/content/news/new?locale=en" aria-label="Add: News" title="Add: News">', $body);
        self::assertStringContainsString('href="/admin/content/articles/new?locale=en" aria-label="Add: Articles"', $body);
        self::assertStringContainsString('href="/admin/content/pages/new?locale=en" aria-label="Add: Pages"', $body);
        self::assertStringContainsString('<a class="admin-bar-action admin-bar-edit" href="/admin/content/articles/' . $this->article->id . '" aria-label="Edit this page" title="Edit this page">', $body);
        self::assertStringContainsString('admin-bar-messages', $body, 'editors read the messages');
        self::assertStringNotContainsString('class="edit-link"', $body, 'the old button is gone');
        self::assertMatchesRegularExpression('~admin-bar-add"[^>]*><svg class="icon"[^>]*><use href="[^"]+#newspaper"></use></svg>\s*<span class="admin-bar-plus" aria-hidden="true">~', $body);
    }

    public function testAnAuthorCannotEditSomeoneElsesEntry(): void
    {
        $this->actingAs($this->createUser('author@example.com', 'author'));

        $body = (string) $this->browser->get('/services/hello')->getBody();

        self::assertStringContainsString('admin-bar-dashboard', $body);
        self::assertStringContainsString('admin-bar-add', $body, 'authors create entries');
        // Edit stays in its place, inactive, saying why.
        self::assertStringContainsString('<span class="admin-bar-action admin-bar-edit" aria-disabled="true" aria-label="You cannot edit this page" title="You cannot edit this page">', $body);
        self::assertStringNotContainsString('<a class="admin-bar-action admin-bar-edit"', $body);
        self::assertStringNotContainsString('admin-bar-messages', $body);
    }

    public function testManagersSeeTheUnreadCount(): void
    {
        $messages = $this->app->container()->service(MessageRepository::class);
        foreach (['Anna', 'Boris'] as $name) {
            $messages->insert(['form' => 'contact', 'locale' => 'en', 'name' => $name, 'email' => 'a@example.com', 'body' => 'Hello there, friend.', 'page_url' => 'https://localhost/contacts', 'ip_hash' => str_repeat('a', 64), 'created_at' => new DateTimeImmutable()]);
        }
        $this->actingAs($this->createUser('admin@example.com'));

        $body = (string) $this->browser->get('/')->getBody();

        self::assertMatchesRegularExpression('~<a class="admin-bar-action admin-bar-messages" href="/admin/messages" aria-label="Messages: 2 new" title="Messages: 2 new">.*?<span class="admin-bar-badge" aria-hidden="true">2</span></a>~s', $body);
        self::assertStringContainsString('<span class="admin-bar-action admin-bar-edit" aria-disabled="true" aria-label="Nothing to edit on this page" title="Nothing to edit on this page">', $body, 'the home page has no entry yet');
    }

    public function testASectionPageLinksItsSectionFormAndHebrewHasTheSameBar(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $section = (string) $this->browser->get('/services')->getBody();
        self::assertStringContainsString('<a class="admin-bar-action admin-bar-edit" href="/admin/sections/' . $this->section->id . '" aria-label="Edit this section"', $section);

        $hebrew = (string) $this->browser->get('/he')->getBody();
        self::assertStringContainsString('<html lang="he" dir="rtl">', $hebrew);
        self::assertStringContainsString('<nav class="admin-bar" aria-label="ניהול האתר">', $hebrew);
        self::assertStringContainsString("href=\"/admin/content/news/new?locale=he\" aria-label=\"הוסף: \u{2068}חדשות\u{2069}\"", $hebrew, 'adds in the page\'s language (the label isolated, as Translator does in RTL)');
    }
}
