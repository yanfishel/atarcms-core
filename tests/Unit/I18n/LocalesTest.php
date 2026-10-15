<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\I18n\Locales;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalesTest extends TestCase
{
    public function testDefaultsToEnglishOnly(): void
    {
        $locales = Locales::fromConfig(new Config());

        self::assertSame('en', $locales->default());
        self::assertSame(['en'], $locales->all());
    }

    public function testReadsConfiguredLocales(): void
    {
        $locales = Locales::fromConfig(new Config(['locales' => ['default' => 'ru', 'locales' => ['ru', 'en', 'pt-br']]]));

        self::assertSame('ru', $locales->default());
        self::assertSame(['ru', 'en', 'pt-br'], $locales->all());
        self::assertTrue($locales->isSupported('pt-br'));
        self::assertFalse($locales->isSupported('RU'));
        self::assertFalse($locales->isSupported('de'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function invalid(): iterable
    {
        yield 'default not listed' => ['de', ['en', 'ru']];
        yield 'uppercase code' => ['en', ['en', 'RU']];
        yield 'path characters' => ['en', ['en', '../x']];
        yield 'duplicate' => ['en', ['en', 'en']];
        yield 'empty list' => ['en', []];
    }

    /**
     * @param list<string> $list
     */
    #[DataProvider('invalid')]
    public function testRejectsInvalidConfiguration(string $default, array $list): void
    {
        $this->expectException(ConfigException::class);

        new Locales($default, $list);
    }

    public function testRejectsNonStringEntries(): void
    {
        $this->expectException(ConfigException::class);

        Locales::fromConfig(new Config(['locales' => ['default' => 'en', 'locales' => ['en', 5]]]));
    }
}
