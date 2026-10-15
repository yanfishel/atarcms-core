<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testFromTextTransliteratesToAscii(): void
    {
        self::assertSame('privet-mir', Slug::fromText('Привет, мир!'));
        self::assertSame('obavlenie', Slug::fromText('Объявление'));
        self::assertSame('hello-world', Slug::fromText('  Hello   World  '));
        self::assertSame('creme-brulee', Slug::fromText('Crème brûlée'));
        self::assertSame('a-b-c', Slug::fromText('a_b.c'));
        self::assertSame('', Slug::fromText('!!!'));
    }

    public function testFromTextStaysWithinTheMaximumLength(): void
    {
        $slug = Slug::fromText(str_repeat('ab ', 100));

        self::assertLessThanOrEqual(Slug::MAX_LENGTH, strlen($slug));
        self::assertTrue(Slug::isValid($slug));
    }

    public function testIsValid(): void
    {
        self::assertTrue(Slug::isValid('a-b1'));
        self::assertTrue(Slug::isValid(str_repeat('a', 190)));
        foreach (['', 'A', 'a--b', '-a', 'a-', 'a_b', 'a/b', 'при', str_repeat('a', 191)] as $bad) {
            self::assertFalse(Slug::isValid($bad), $bad);
        }
    }

    public function testWithSuffix(): void
    {
        self::assertSame('about-2', Slug::withSuffix('about', 2));
        $long = Slug::withSuffix(str_repeat('a', 190), 12);
        self::assertSame(190, strlen($long));
        self::assertStringEndsWith('a-12', $long);
    }
}
