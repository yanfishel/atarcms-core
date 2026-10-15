<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\TermInput;
use AtarCms\Content\TermService;
use AtarCms\Database\Connection;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Support\QueryCounter;

/**
 * The fixture theme prints the $seo the controllers resolve (PageSeo) as the <title>, the meta description and the
 * Open Graph texts: articles and section pages inherit their sections' texts, a section title is inherited only as a
 * template with "{title}", the site description comes last, and pages outside sections keep their own texts.
 */
final class SectionSeoTest extends ContentFunctionalTestCase
{
    public function testArticlesAndSectionPagesInheritTheTextsOfTheirSections(): void
    {
        $guides = $this->term('sections', 'Guides', ['metaTitle' => '{title} — Guides & "more"', 'metaDescription' => 'All <guides>.']);
        $php = $this->term('sections', 'PHP', ['parent' => $guides, 'metaTitle' => 'PHP <books>']);
        $this->term('sections', 'Tools', ['parent' => $guides]);
        $this->entry('stories', 'Hello & {title}', ['slug' => 'hello', 'terms' => ['sections' => [$php->id]]]);
        $this->entry('stories', 'Own', ['slug' => 'own', 'metaTitle' => 'My own', 'metaDescription' => 'Mine.', 'terms' => ['sections' => [$php->id]]]);

        $hello = (string) $this->browser->get('/guides/php/hello')->getBody();
        self::assertStringContainsString('<title>Hello &amp; {title} — Guides &amp; &quot;more&quot;</title>', $hello, 'PHP’s title has no {title}: Guides’ template, filled once');
        self::assertStringContainsString('<meta name="description" content="All &lt;guides&gt;.">', $hello);
        self::assertStringContainsString('<meta property="og:title" content="Hello &amp; {title} — Guides &amp; &quot;more&quot;">', $hello);
        self::assertStringContainsString('<meta property="og:description" content="All &lt;guides&gt;.">', $hello);

        $own = (string) $this->browser->get('/guides/php/own')->getBody();
        self::assertStringContainsString('<title>My own</title>', $own);
        self::assertStringContainsString('<meta name="description" content="Mine.">', $own);
        self::assertStringContainsString('<meta property="og:title" content="My own">', $own);

        $section = (string) $this->browser->get('/guides/php')->getBody();
        self::assertStringContainsString('<title>PHP &lt;books&gt;</title>', $section, 'its own title applies to its own page');
        self::assertStringContainsString('<meta name="description" content="All &lt;guides&gt;.">', $section);
        self::assertStringContainsString('<meta property="og:title" content="PHP &lt;books&gt;">', $section);
        self::assertStringContainsString('<title>Tools — Guides &amp; &quot;more&quot;</title>', (string) $this->browser->get('/guides/tools')->getBody(), 'the parent’s template with the subsection’s name');
    }

    public function testWithoutInheritableTextsThePageTitleAndTheSiteDescriptionApply(): void
    {
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput('Fishing club', 'Site words.'), null, null);
        $guides = $this->term('sections', 'Guides', ['metaTitle' => 'All the guides']);
        $php = $this->term('sections', 'PHP', ['parent' => $guides]);
        $this->entry('stories', 'Hello', ['slug' => 'hello', 'terms' => ['sections' => [$php->id]]]);

        $hello = (string) $this->browser->get('/guides/php/hello')->getBody();
        self::assertStringContainsString('<title>Hello</title>', $hello);
        self::assertStringContainsString('<meta name="description" content="Site words.">', $hello);
        self::assertStringContainsString('<meta property="og:description" content="Site words.">', $hello);

        $section = (string) $this->browser->get('/guides/php')->getBody();
        self::assertStringContainsString('<title>PHP</title>', $section);
        self::assertStringContainsString('<meta name="description" content="Site words.">', $section);
    }

    /**
     * Texts belong to one language: a translated section starts without them, so its articles keep their own titles.
     */
    public function testTheTextsArePerLanguage(): void
    {
        $guides = $this->term('sections', 'Guides', ['metaTitle' => '{title} — Guides', 'metaDescription' => 'All guides.']);
        $russian = $this->app->container()->service(TermService::class)->createTranslation($guides, 'ru', null, null);
        $this->entry('stories', 'Привет', ['locale' => 'ru', 'slug' => 'privet', 'terms' => ['sections' => [$russian->id]]]);

        $body = (string) $this->browser->get('/ru/guides/privet')->getBody();

        self::assertStringContainsString('<title>Привет</title>', $body);
        self::assertStringNotContainsString('name="description"', $body);
        self::assertStringNotContainsString('og:description', $body);
    }

    /**
     * Pages outside sections use their own texts, else their title and the site description.
     */
    public function testPagesOutsideSectionsKeepTheirOwnTexts(): void
    {
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput('Fishing club', 'Site words.'), null, null);
        $this->term('sections', 'Guides', ['metaTitle' => '{title} — Guides', 'metaDescription' => 'All guides.']);
        $this->entry('pages', 'About', ['slug' => 'about', 'metaTitle' => 'About the club', 'metaDescription' => 'Who we are.']);
        $this->entry('pages', 'Plain', ['slug' => 'plain']);

        $about = (string) $this->browser->get('/pages/about')->getBody();
        self::assertStringContainsString('<title>About the club</title>', $about);
        self::assertStringContainsString('<meta name="description" content="Who we are.">', $about);

        $plain = (string) $this->browser->get('/pages/plain')->getBody();
        self::assertStringContainsString('<title>Plain</title>', $plain);
        self::assertStringContainsString('<meta name="description" content="Site words.">', $plain);
    }

    /**
     * The controllers resolve the texts from the section terms they load anyway: texts on every level cost no query.
     */
    public function testInheritedTextsCostNoQueries(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP', ['parent' => $guides]);
        $basics = $this->term('sections', 'Basics', ['parent' => $php]);
        $this->entry('stories', 'One', ['slug' => 'one', 'terms' => ['sections' => [$basics->id]]]);
        $db = $this->app->container()->service(Connection::class);
        $this->browser->get('/guides/php/basics/one');
        $this->browser->get('/guides/php/basics');
        $article = QueryCounter::measure($db, fn() => $this->browser->get('/guides/php/basics/one'));
        $section = QueryCounter::measure($db, fn() => $this->browser->get('/guides/php/basics'));

        $service = $this->app->container()->service(TermService::class);
        $service->update($guides, new TermInput('Guides', metaTitle: '{title} | Guides', metaDescription: 'All guides.'), null, null);
        $service->update($php, new TermInput('PHP', parentId: $guides->id, metaTitle: 'PHP'), null, null);
        $this->browser->get('/guides/php/basics/one');
        $this->browser->get('/guides/php/basics');

        self::assertSame($article, QueryCounter::measure($db, fn() => $this->browser->get('/guides/php/basics/one')));
        self::assertSame($section, QueryCounter::measure($db, fn() => $this->browser->get('/guides/php/basics')));
        self::assertStringContainsString('<title>One | Guides</title>', (string) $this->browser->get('/guides/php/basics/one')->getBody());
        self::assertStringContainsString('<title>Basics | Guides</title>', (string) $this->browser->get('/guides/php/basics')->getBody());
    }
}
