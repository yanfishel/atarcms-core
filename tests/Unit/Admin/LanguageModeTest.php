<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\LanguageMode;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * locales.admin chooses how the admin's languages behave: one linked switch (the default) or the interface and the
 * content language apart.
 */
final class LanguageModeTest extends TestCase
{
    public function testLinkedIsTheDefault(): void
    {
        self::assertSame(LanguageMode::Linked, LanguageMode::fromConfig(new Config([])));
        self::assertSame(LanguageMode::Linked, LanguageMode::fromConfig(new Config(['locales' => ['default' => 'en']])));
    }

    public function testBothValuesAreRead(): void
    {
        self::assertSame(LanguageMode::Linked, LanguageMode::fromConfig(new Config(['locales' => ['admin' => 'linked']])));
        self::assertSame(LanguageMode::Separate, LanguageMode::fromConfig(new Config(['locales' => ['admin' => 'separate']])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'unknown' => ['joined'];
        yield 'upper case' => ['Linked'];
        yield 'empty' => [''];
        yield 'a boolean' => [true];
        yield 'null' => [null];
    }

    #[DataProvider('invalid')]
    public function testAnythingElseStopsTheBoot(mixed $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('locales.admin must be "linked" or "separate"');

        LanguageMode::fromConfig(new Config(['locales' => ['admin' => $value]]));
    }
}
