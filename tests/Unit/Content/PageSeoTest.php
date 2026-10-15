<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\PageSeo;
use AtarCms\Content\Seo;
use AtarCms\Content\Term;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The resolution chain of a page's title and description: own texts, then the sections from the nearest up, each text
 * on its own; a section title is inherited only as a template with "{title}".
 */
final class PageSeoTest extends TestCase
{
    public function testAnArticleInheritsTheNearestTemplateAndTheNearestDescriptionOnTheirOwn(): void
    {
        $about = self::term(1, 'About', null, '{title} — Repair in Moscow', 'We repair things.');
        $team = self::term(2, 'Team', 1, 'Our team', null);
        $people = self::term(3, 'People', 2, null, 'The people who repair.');

        $seo = PageSeo::forEntry(self::entry('Ann'), [$about, $team, $people]);

        self::assertSame('Ann — Repair in Moscow', $seo->title, 'Team’s title has no {title}: it is for its own page only');
        self::assertSame('The people who repair.', $seo->description, 'the nearest description');
        self::assertSame(
            'Ann (people)',
            PageSeo::forEntry(self::entry('Ann'), [$about, self::term(4, 'Deep', 1, '{title} (people)', null)])->title,
            'a deeper template wins',
        );
    }

    public function testTheEntrysOwnTextsWinAsTheyAre(): void
    {
        $about = self::term(1, 'About', null, '{title} — Repair', 'Section text.');

        $seo = PageSeo::forEntry(self::entry('Ann', '{title} by hand', 'Own text.'), [$about]);

        self::assertSame(['{title} by hand', 'Own text.'], [$seo->title, $seo->description], 'an entry’s own title is no template');
        $blank = PageSeo::forEntry(self::entry('Ann', '  ', ''), [$about]);
        self::assertSame(['Ann — Repair', 'Section text.'], [$blank->title, $blank->description], 'empty is none');
    }

    public function testWithoutSectionsOrTextsThePageKeepsItsTitleAndHasNoDescription(): void
    {
        $plain = self::term(1, 'About', null, 'About us', null);

        self::assertEquals(new PageSeo('Ann', null), PageSeo::forEntry(self::entry('Ann'), []));
        self::assertEquals(new PageSeo('Ann', null), PageSeo::forEntry(self::entry('Ann'), [$plain]), 'a title without {title} is not inherited');
        self::assertEquals(new PageSeo('Own', 'Mine.'), PageSeo::forEntry(self::entry('Ann', 'Own', 'Mine.'), []), 'a page outside sections');
    }

    public function testTheTemplateIsFilledOnceAndKeepsBracesAndMarkupAsText(): void
    {
        $about = self::term(1, 'About', null, '<b>{title}</b> & {title}', null);

        self::assertSame('<b>Say {title}</b> & Say {title}', PageSeo::forEntry(self::entry('Say {title}'), [$about])->title, 'escaping is the theme’s job');
        self::assertSame('{title', PageSeo::fill('{title', 'Ann'));
        self::assertSame('No placeholder', PageSeo::fill('No placeholder', 'Ann'));
    }

    public function testASectionPageUsesItsOwnTextsThenItsParentsTemplate(): void
    {
        $about = self::term(1, 'About', null, '{title} — Repair', 'We repair things.');
        $team = self::term(2, 'Team', 1, 'Our team', 'Who we are.');
        $people = self::term(3, 'People', 2, null, null);
        $named = self::term(4, 'Named', 2, '{title}!', null);

        self::assertEquals(new PageSeo('Our team', 'Who we are.'), PageSeo::forTerm($team, [$about]), 'its own title, template or not');
        self::assertEquals(new PageSeo('Named!', 'Who we are.'), PageSeo::forTerm($named, [$about, $team]), 'its own template takes its name');
        self::assertEquals(new PageSeo('People — Repair', 'Who we are.'), PageSeo::forTerm($people, [$about, $team]), 'Team’s plain title is skipped');
        self::assertEquals(new PageSeo('About — Repair', 'We repair things.'), PageSeo::forTerm($about, []));
        self::assertEquals(new PageSeo('Tag', null), PageSeo::forTerm(self::term(5, 'Tag', null, null, null), []));
    }

    public function testTheInheritedTermsAreTheNearestWithATemplateAndWithADescription(): void
    {
        $about = self::term(1, 'About', null, '{title} — Repair', 'We repair things.');
        $team = self::term(2, 'Team', 1, 'Our team', null);

        self::assertSame($about, PageSeo::inheritedTitle([$about, $team]));
        self::assertSame($about, PageSeo::inheritedDescription([$about, $team]));
        self::assertNull(PageSeo::inheritedTitle([$team]));
        self::assertNull(PageSeo::inheritedDescription([]));
    }

    private static function term(int $id, string $name, ?int $parentId, ?string $title, ?string $description): Term
    {
        $now = new DateTimeImmutable('2026-10-02 12:00:00');

        return new Term($id, 'sections', 'en', str_repeat('a', 32), strtolower($name), $name, $parentId, 0, $now, $now, new Seo($title, $description));
    }

    private static function entry(string $title, ?string $metaTitle = null, ?string $metaDescription = null): Entry
    {
        $now = new DateTimeImmutable('2026-10-02 12:00:00');

        return new Entry(1, Collection::make('stories'), 'en', 'group', 'slug', $title, EntryStatus::Published, $now, null, 0, null, $now, $now, new Seo($metaTitle, $metaDescription), []);
    }
}
