<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Search;

use AtarCms\Search\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function testRussianWordFormsShareAStem(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame('новост новост новост', $normalizer->normalize('Новость, новостями; НОВОСТИ', 'ru'));
        self::assertSame(['москв', 'москв'], $normalizer->words('Москва москве', 'ru'));
    }

    public function testEnglishWordFormsShareAStem(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame('run run run connect connect', $normalizer->normalize('Run, running RUNS: connection connected', 'en'));
    }

    public function testGermanWordFormsShareAStem(): void
    {
        $normalizer = new TextNormalizer();

        $stems = $normalizer->words('Zeitung Zeitungen ZEITUNGEN', 'de');
        self::assertCount(1, array_unique($stems), implode(' ', $stems));
        self::assertSame($normalizer->words('Häuser', 'de-AT'), $normalizer->words('häuser', 'de'));
        self::assertSame('haus', $normalizer->normalize('Häuser', 'de'), 'the umlaut of the plural goes');
        self::assertSame($normalizer->normalize('Kinder', 'de'), $normalizer->normalize('Kindern', 'de'));
    }

    public function testFrenchWordFormsShareAStem(): void
    {
        $normalizer = new TextNormalizer();

        $stems = $normalizer->words('Actualité actualités ACTUALITÉS', 'fr');
        self::assertCount(1, array_unique($stems), implode(' ', $stems));
        self::assertSame($normalizer->normalize('continuer', 'fr'), $normalizer->normalize('continuation', 'fr'));
        self::assertSame($normalizer->words('maisons', 'fr_CA'), $normalizer->words('maison', 'fr'));
    }

    public function testYoIsReadAsYe(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame($normalizer->normalize('ежик', 'ru'), $normalizer->normalize('Ёжик', 'ru'));
        self::assertSame('елк', $normalizer->normalize('ёлка', 'ru'));
    }

    public function testPunctuationSplitsWordsAndDigitsStay(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame(['report', '2024', 'php', '8', '4', 'x1'], $normalizer->words('"Report" 2024 — PHP-8.4 (x1)!', 'en'));
        self::assertSame([], $normalizer->words(' +-*"~<>()@ ', 'en'));
    }

    public function testTheStemmerIsChosenByTheLanguageOfTheLocale(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame('новост', $normalizer->normalize('новостями', 'ru-RU'));
        self::assertSame('run', $normalizer->normalize('running', 'en_GB'));
    }

    public function testALocaleWithoutAStemmerIsOnlyLowerCased(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame('running zeitungen елка', $normalizer->normalize('Running Zeitungen Ёлка', 'pl'));
        self::assertSame('שלום עולם', $normalizer->normalize('שלום עולם', 'he'));
    }

    public function testInvalidUtf8GivesNoWords(): void
    {
        self::assertSame([], (new TextNormalizer())->words("caf\xE9", 'en'));
    }
}
