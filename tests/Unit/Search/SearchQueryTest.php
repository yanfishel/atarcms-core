<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Search;

use AtarCms\Search\SearchQuery;
use AtarCms\Search\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    public function testEveryWordBecomesARequiredPrefixStem(): void
    {
        $query = SearchQuery::parse('  Новости Москвы ', 'ru', new TextNormalizer());

        self::assertSame('Новости Москвы', $query->text);
        self::assertSame(['новост', 'москв'], $query->stems);
        self::assertSame('+новост* +москв*', $query->boolean());
        self::assertFalse($query->isEmpty());
    }

    public function testStemsShorterThanThreeCharactersAreDropped(): void
    {
        self::assertSame(['run'], SearchQuery::parse('go running', 'en', new TextNormalizer())->stems);
        self::assertTrue(SearchQuery::parse('a an to it is', 'en', new TextNormalizer())->isEmpty());
        self::assertTrue(SearchQuery::parse('', 'en', new TextNormalizer())->isEmpty());
        self::assertSame('', SearchQuery::parse('', 'en', new TextNormalizer())->boolean());
    }

    public function testTheTextIsCutTo200Characters(): void
    {
        $query = SearchQuery::parse(str_repeat('ж', 150) . ' ' . str_repeat('ы', 100), 'xx', new TextNormalizer());

        self::assertSame(200, mb_strlen($query->text));
        self::assertSame([str_repeat('ж', 150), str_repeat('ы', 49)], $query->stems);
    }

    public function testAtMostTenDistinctStemsAreKept(): void
    {
        $query = SearchQuery::parse('100 100 101 102 103 104 105 106 107 108 109 110 111', 'en', new TextNormalizer());

        self::assertSame(['100', '101', '102', '103', '104', '105', '106', '107', '108', '109'], $query->stems);
    }

    public function testBooleanOperatorsQuotesAndWildcardsCannotPass(): void
    {
        $query = SearchQuery::parse('+php -"sql injection" ~x* (union) <select> >abc @2 \'or\' 1=1', 'en', new TextNormalizer());

        self::assertSame('+php* +sql* +inject* +union* +select* +abc*', $query->boolean());
        self::assertMatchesRegularExpression('/^\+[\p{L}\p{N}]+\*(?: \+[\p{L}\p{N}]+\*)*$/u', $query->boolean());
    }

    public function testInvalidUtf8IsAnEmptyQuery(): void
    {
        $query = SearchQuery::parse("caf\xE9 running", 'en', new TextNormalizer());

        self::assertSame('', $query->text);
        self::assertTrue($query->isEmpty());
    }
}
