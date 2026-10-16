<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Demo;

use AtarCms\Demo\DemoStructure;
use AtarCms\Demo\DemoTexts;
use AtarCms\Kernel\Config;
use PHPUnit\Framework\TestCase;

final class DemoStructureTest extends TestCase
{
    private const array VALID = [
        'sections' => [
            'services-x' => [
                'names' => ['ru' => 'Услуги'],
                'children' => [
                    'repair' => ['ru' => 'Ремонт'],
                    'design' => ['ru' => 'Дизайн', 'en' => 'Design'],
                ],
            ],
            'flat' => ['names' => ['ru' => 'Без подразделов']],
        ],
        'pages' => ['about-me' => ['ru' => 'Обо мне']],
        'tags' => ['ru' => ['Кухни', 'Ванные']],
    ];

    public function testATypoInALanguageKeyIsAProblem(): void
    {
        // The site has en and ru. A names/titles/tags key in another language is almost always a typo ('ruu'),
        // which would otherwise silently fall back to lorem text — refuse before the wipe instead.
        $locales = ['locales' => ['default' => 'en', 'locales' => ['en', 'ru']]];

        $sections = DemoStructure::fromConfig(new Config($locales + ['demo' => ['sections' => ['a' => ['names' => ['ruu' => 'Опечатка']]]]]));
        self::assertCount(1, $sections->problems());
        self::assertStringContainsString('"ruu"', $sections->problems()[0]);

        $child = DemoStructure::fromConfig(new Config($locales + ['demo' => ['sections' => ['a' => ['names' => ['ru' => 'А'], 'children' => ['b' => ['ruu' => 'Опечатка']]]]]]));
        self::assertCount(1, $child->problems());

        $pages = DemoStructure::fromConfig(new Config($locales + ['demo' => ['pages' => ['about-me' => ['ruu' => 'Обо мне']]]]));
        self::assertCount(1, $pages->problems());

        $tags = DemoStructure::fromConfig(new Config($locales + ['demo' => ['tags' => ['ruu' => ['Кухни']]]]));
        self::assertCount(1, $tags->problems());

        $valid = DemoStructure::fromConfig(new Config($locales + ['demo' => self::VALID]));
        self::assertSame([], $valid->problems());
    }

    public function testASlugUsedTwiceAcrossTheFileIsAProblem(): void
    {
        // Term slugs are unique per taxonomy, and a page shares the root address space with the top sections:
        // an in-file conflict would only surface as a skipped item after the wipe — say it up front instead.
        $twice = DemoStructure::fromConfig(new Config(['demo' => ['sections' => [
            'a' => ['names' => ['ru' => 'А'], 'children' => ['x' => ['ru' => 'Икс']]],
            'b' => ['names' => ['ru' => 'Б'], 'children' => ['x' => ['ru' => 'Икс тоже']]],
        ]]]));
        self::assertCount(1, $twice->problems());
        self::assertStringContainsString('"x"', $twice->problems()[0]);

        $pageVsSection = DemoStructure::fromConfig(new Config(['demo' => [
            'sections' => ['about-me' => ['names' => ['ru' => 'Обо мне']]],
            'pages' => ['about-me' => ['ru' => 'Обо мне']],
        ]]));
        self::assertCount(1, $pageVsSection->problems());
        self::assertStringContainsString('"about-me"', $pageVsSection->problems()[0]);

        // A child under one parent and a page may share a slug: their addresses differ.
        $childVsPage = DemoStructure::fromConfig(new Config(['demo' => [
            'sections' => ['a' => ['names' => ['ru' => 'А'], 'children' => ['about-me' => ['ru' => 'Икс']]]],
            'pages' => ['about-me' => ['ru' => 'Обо мне']],
        ]]));
        self::assertSame([], $childVsPage->problems());
    }

    public function testWithoutTheFileTheGenericStructureIsUsed(): void
    {
        $structure = DemoStructure::fromConfig(new Config([]));

        self::assertSame(DemoTexts::SECTIONS, $structure->sections());
        self::assertSame(DemoTexts::PAGES, $structure->pages());
        self::assertSame(DemoTexts::TAGS, $structure->tags());
        self::assertSame([], $structure->problems());
    }

    public function testEachMissingKeyFallsBackOnItsOwn(): void
    {
        $structure = DemoStructure::fromConfig(new Config(['demo' => ['pages' => ['about-me' => ['ru' => 'Обо мне']]]]));

        self::assertSame(DemoTexts::SECTIONS, $structure->sections());
        self::assertSame(['about-me' => ['ru' => 'Обо мне']], $structure->pages());
        self::assertSame(DemoTexts::TAGS, $structure->tags());
    }

    public function testAValidFileComesBackAsGiven(): void
    {
        $structure = DemoStructure::fromConfig(new Config(['demo' => self::VALID]));

        self::assertSame([
            'services-x' => [
                'names' => ['ru' => 'Услуги'],
                'children' => [
                    'repair' => ['ru' => 'Ремонт'],
                    'design' => ['ru' => 'Дизайн', 'en' => 'Design'],
                ],
            ],
            'flat' => ['names' => ['ru' => 'Без подразделов'], 'children' => []],
        ], $structure->sections());
        self::assertSame(self::VALID['pages'], $structure->pages());
        self::assertSame(self::VALID['tags'], $structure->tags());
        self::assertSame([], $structure->problems());
    }

    public function testAnEmptyListMeansNone(): void
    {
        $structure = DemoStructure::fromConfig(new Config(['demo' => ['sections' => [], 'pages' => [], 'tags' => []]]));

        self::assertSame([[], [], []], [$structure->sections(), $structure->pages(), $structure->tags()]);
        self::assertSame([], $structure->problems());
    }

    /**
     * @param array<string, mixed> $demo
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testAMalformedFileIsReported(mixed $demo, string $expected): void
    {
        $problems = DemoStructure::fromConfig(new Config(['demo' => $demo]))->problems();

        self::assertCount(1, $problems, implode("\n", $problems));
        self::assertStringStartsWith('config/demo.php', $problems[0]);
        self::assertStringContainsString($expected, $problems[0]);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function malformed(): iterable
    {
        yield 'not an array' => ['sections', 'must return an array'];
        yield 'an unknown key' => [['section' => []], '"section"'];
        yield 'sections not an array' => [['sections' => 'services'], '"sections"'];
        yield 'a section that is not an array' => [['sections' => ['services' => 'Услуги']], '"services"'];
        yield 'a section slug the core refuses' => [['sections' => ['Услуги' => ['names' => ['ru' => 'Услуги']]]], '"Услуги"'];
        yield 'a numbered section' => [['sections' => [['names' => ['ru' => 'Услуги']]]], '"sections"'];
        yield 'an unknown section key' => [['sections' => ['services' => ['name' => ['ru' => 'Услуги']]]], '"name"'];
        yield 'names that are not locale => string' => [['sections' => ['services' => ['names' => ['Услуги']]]], 'names'];
        yield 'a name that is not a string' => [['sections' => ['services' => ['names' => ['ru' => ['Услуги']]]]], 'names'];
        yield 'children that are not an array' => [['sections' => ['services' => ['children' => 'repair']]], 'children'];
        yield 'a child with a bad slug' => [['sections' => ['services' => ['children' => ['Ремонт' => ['ru' => 'Ремонт']]]]], '"Ремонт"'];
        yield 'a child that is not names' => [['sections' => ['services' => ['children' => ['repair' => 'Ремонт']]]], '"repair"'];
        yield 'a list of pages' => [['pages' => [['ru' => 'Обо мне']]], '"pages"'];
        yield 'a list of children' => [['sections' => ['services' => ['children' => [['ru' => 'Ремонт']]]]], 'children'];
        yield 'pages not an array' => [['pages' => 'about'], '"pages"'];
        yield 'a page with a bad slug' => [['pages' => ['about/me' => ['ru' => 'Обо мне']]], '"about/me"'];
        yield 'a page title that is not a string' => [['pages' => ['about' => ['ru' => 1]]], '"about"'];
        yield 'tags not an array' => [['tags' => 'Кухни'], '"tags"'];
        yield 'tags without a locale' => [['tags' => ['Кухни', 'Ванные']], '"tags"'];
        yield 'tags that are not a list' => [['tags' => ['ru' => ['a' => 'Кухни']]], '"ru"'];
        yield 'a tag that is not a string' => [['tags' => ['ru' => ['Кухни', 2]]], '"ru"'];
        yield 'an empty tag' => [['tags' => ['ru' => ['Кухни', ' ']]], '"ru"'];
    }
}
