<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Search;

use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Seo;
use AtarCms\Search\SearchResult;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
    public function testTheMetaDescriptionWins(): void
    {
        self::assertSame('Written for search engines', SearchResult::describe(self::entry(['summary' => 'Summary'], 'Written for search engines')));
    }

    public function testOtherwiseTheFirstNonEmptyTextFieldIsUsed(): void
    {
        self::assertSame('Summary text', SearchResult::describe(self::entry(['summary' => 'Summary text', 'body' => '<p>Body</p>'])));
        self::assertSame('First second', SearchResult::describe(self::entry(['rating' => 5, 'body' => '<p>First</p><p>second</p>'])));
        self::assertSame('', SearchResult::describe(self::entry(['rating' => 5])));
    }

    public function testLongTextIsCutAtAWordBoundary(): void
    {
        $text = str_repeat('word ', 50);

        $excerpt = SearchResult::describe(self::entry(['summary' => $text]));

        self::assertSame(trim(str_repeat('word ', 40)) . '…', $excerpt);
        self::assertSame('Short text', SearchResult::excerpt("  Short \n text ", 200));
        self::assertSame('one two…', SearchResult::excerpt('one two three', 8));
        self::assertSame('one two…', SearchResult::excerpt('one two, three', 8));
        self::assertSame('abcde…', SearchResult::excerpt('abcdefghij', 5));
    }

    public function testTheCutIsTrimmedWithoutBreakingMultibyteCharacters(): void
    {
        $excerpt = SearchResult::excerpt('Новый театр — открытие сезона', 12);

        self::assertSame('Новый театр…', $excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
        self::assertSame('Новый театр…', SearchResult::excerpt('Новый театр, открытие', 12));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function entry(array $data, ?string $metaDescription = null): Entry
    {
        $collection = Collection::make('articles')->route('/articles/{slug}')->fields([
            Field::number('rating'),
            Field::textarea('summary'),
            Field::richText('body'),
        ]);
        $now = new DateTimeImmutable('2026-09-30 12:00:00');

        return new Entry(1, $collection, 'en', 'group', 'slug', 'Title', EntryStatus::Published, $now, null, 0, null, $now, $now, new Seo(metaDescription: $metaDescription), $data);
    }
}
