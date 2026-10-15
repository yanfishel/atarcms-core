<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Content\TermService;
use AtarCms\Http\Exception\ValidationFailed;

final class RootRoutesTest extends ContentFunctionalTestCase
{
    public function testPagesAndSectionsShareTheRoot(): void
    {
        $this->entry('info', 'About us', ['slug' => 'about']);
        $this->term('sections', 'Guides');

        $page = $this->browser->get('/about');
        $section = $this->browser->get('/guides');

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('<div class="generic" data-collection="info">', (string) $page->getBody());
        self::assertSame(200, $section->getStatusCode());
        self::assertStringContainsString('<h1 class="term" data-taxonomy="sections">Guides</h1>', (string) $section->getBody());
        self::assertStringContainsString('<link rel="canonical" href="https://localhost/guides">', (string) $section->getBody());
        self::assertSame(404, $this->browser->get('/ghost')->getStatusCode());
    }

    public function testASectionAnswersWhereNoPublicPageDoes(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->app->container()->service(TermService::class)->createTranslation($guides, 'ru', null, null);
        $this->entry('info', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);

        // There is no Russian page "guides": the section answers (UntranslatedRootPagesTest: untranslated pages).
        $russian = $this->browser->get('/ru/guides');

        self::assertSame(200, $russian->getStatusCode());
        self::assertStringContainsString('<link rel="alternate" hreflang="ru" href="https://localhost/ru/guides">', (string) $russian->getBody());
        self::assertSame(404, $this->browser->get('/draft')->getStatusCode());
        $missing = $this->browser->get('/ru/ghost');
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringNotContainsString('hreflang', (string) $missing->getBody(), 'an error page has no alternates, although "/{slug}" matched');
    }

    public function testTheSiteKeepsPageAndSectionSlugsApart(): void
    {
        $this->entry('info', 'About us', ['slug' => 'about']);

        try {
            $this->term('sections', 'About', ['slug' => 'about']);
            self::fail('A section took the slug of a page');
        } catch (ValidationFailed $e) {
            self::assertSame(['slug' => 'validation.slug.reserved'], $e->errors);
        }
        self::assertSame('about-2', $this->term('sections', 'About')->slug);
        $guides = $this->term('sections', 'Guides');
        $this->term('sections', 'About', ['slug' => 'about', 'parent' => $guides]);
        $this->term('sections', 'Team', ['parent' => $guides]);
        self::assertStringContainsString('<div class="generic" data-collection="info">', (string) $this->browser->get('/about')->getBody());
        self::assertStringContainsString('<h1 class="term" data-taxonomy="sections">About</h1>', (string) $this->browser->get('/guides/about')->getBody());
    }
}
