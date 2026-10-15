<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;

final class ContentPagesTest extends ContentFunctionalTestCase
{
    public function testShowsAPublishedEntryWithItsCollectionTemplate(): void
    {
        $this->entry('articles', 'Hello <world>', [
            'slug' => 'hello',
            'fields' => ['body' => '<p>Safe <strong>bold</strong><script>alert(1)</script></p>', 'video' => 'dQw4w9WgXcQ'],
        ]);

        $response = $this->browser->get('/articles/hello');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Hello &lt;world&gt;</title>', $body);
        self::assertStringContainsString('<h1>Hello &lt;world&gt;</h1>', $body);
        self::assertStringContainsString('<div class="body"><p>Safe <strong>bold</strong></p></div>', $body);
        self::assertStringNotContainsString('alert(1)', $body);
        self::assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $body);
        self::assertStringNotContainsString('name="robots"', $body);
    }

    public function testInvisibleEntriesAreNotFound(): void
    {
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->entry('articles', 'Soon', ['slug' => 'soon', 'status' => EntryStatus::Scheduled, 'publishedAt' => $this->clock->now()->modify('+1 day')]);
        $this->entry('articles', 'English', ['slug' => 'english']);

        foreach (['/articles/draft', '/articles/soon', '/articles/missing', '/ru/articles/english', '/articles/English'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }

        $this->clock->advance('+2 days');
        self::assertSame(200, $this->browser->get('/articles/soon')->getStatusCode());
    }

    public function testPreviewShowsDraftsOnlyToUsersWhoCanViewTheCollection(): void
    {
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);

        self::assertSame(404, $this->browser->get('/articles/draft?preview=1')->getStatusCode());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame(404, $this->browser->get('/articles/draft')->getStatusCode());
        $preview = $this->browser->get('/articles/draft?preview=1');
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame('no-store, private', $preview->getHeaderLine('Cache-Control'));
        self::assertSame('noindex', $preview->getHeaderLine('X-Robots-Tag'));
    }

    public function testPreviewIsNotFoundForUsersWhoseRoleCannotViewTheCollection(): void
    {
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);

        $this->actingAs($this->createUser('nobody@example.com', 'nobody'));

        self::assertSame(404, $this->browser->get('/articles/draft?preview=1')->getStatusCode());
    }

    public function testPreviewCanonicalRedirectIsNotCached(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about, 'status' => EntryStatus::Draft]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $redirect = $this->browser->get('/pages/team?preview=1');

        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame('/pages/about/team?preview=1', $redirect->getHeaderLine('Location'));
        self::assertSame('no-store, private', $redirect->getHeaderLine('Cache-Control'));
        self::assertSame('noindex', $redirect->getHeaderLine('X-Robots-Tag'));
    }

    public function testHierarchicalPagesUseNestedPathsAndRedirectToTheCanonicalOne(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $team = $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->entry('pages', 'Other', ['slug' => 'other']);
        $this->translation($about, 'ru', 'О нас', 'o-nas');
        $this->translation($team, 'ru', 'Команда', 'komanda');

        $page = $this->browser->get('/pages/about/team');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('<div class="generic" data-collection="pages">', (string) $page->getBody());
        self::assertStringContainsString('<h1>Team</h1>', (string) $page->getBody());

        $short = $this->browser->get('/pages/team');
        self::assertSame(301, $short->getStatusCode());
        self::assertSame('/pages/about/team', $short->getHeaderLine('Location'));
        self::assertSame('/pages/about/team', $this->browser->get('/pages/other/team')->getHeaderLine('Location'));
        self::assertSame('/ru/pages/o-nas/komanda', $this->browser->get('/ru/pages/komanda')->getHeaderLine('Location'));
        self::assertSame(200, $this->browser->get('/ru/pages/o-nas/komanda')->getStatusCode());
        self::assertSame(404, $this->browser->get('/pages/about/nope')->getStatusCode());
    }

    public function testSinglesRenderWithOrWithoutTheirEntry(): void
    {
        $empty = $this->browser->get('/contact');
        self::assertSame(200, $empty->getStatusCode());
        self::assertStringContainsString('<p class="empty">', (string) $empty->getBody());

        $this->entry('contact', 'Contact us', ['fields' => ['phone' => '+1 555']]);
        self::assertStringContainsString('<h1>Contact us</h1>', (string) $this->browser->get('/contact')->getBody());

        $this->entry('contact', 'Контакты', ['locale' => 'ru', 'status' => EntryStatus::Draft, 'fields' => ['phone' => '1']]);
        self::assertStringContainsString('<p class="empty">', (string) $this->browser->get('/ru/contact')->getBody());
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertStringContainsString('<h1>Контакты</h1>', (string) $this->browser->get('/ru/contact?preview=1')->getBody());
    }

    public function testModuleRoutesStillWin(): void
    {
        self::assertStringContainsString('<p class="slug">hello</p>', (string) $this->browser->get('/news/hello')->getBody());
    }
}
