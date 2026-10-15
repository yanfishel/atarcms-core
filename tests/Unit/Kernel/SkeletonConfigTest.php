<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel;

use AtarCms\Admin\LanguageMode;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkeletonConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function environments(): iterable
    {
        yield 'padded upper case' => [['APP_ENV' => ' Production '], 'production'];
        yield 'local upper case' => [['APP_ENV' => 'Local'], 'local'];
        yield 'unset' => [[], 'production'];
        yield 'empty' => [['APP_ENV' => ''], 'production'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('environments')]
    public function testAppEnvResolvesToTheNormalizedEnvironment(array $values, string $expected): void
    {
        $factory = require __DIR__ . '/../../../skeleton/config/app.php';
        self::assertIsCallable($factory);

        $app = $factory(Env::fromArray($values));

        self::assertIsArray($app);
        self::assertSame($expected, (new Config(['app' => $app]))->environment());
    }

    /**
     * The skeleton names the admin's language mode explicitly, and it is the default: one linked switch.
     */
    public function testTheSkeletonLinksTheAdminLanguages(): void
    {
        $locales = require __DIR__ . '/../../../skeleton/config/locales.php';
        self::assertIsArray($locales);

        self::assertSame('linked', $locales['admin'] ?? null);
        self::assertSame(LanguageMode::Linked, LanguageMode::fromConfig(new Config(['locales' => $locales])));
    }

    public function testMissingAppUrlFailsTheBootCheck(): void
    {
        $factory = require __DIR__ . '/../../../skeleton/config/app.php';
        self::assertIsCallable($factory);
        $app = $factory(Env::fromArray([]));
        self::assertIsArray($app);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('app.url');

        (new Config(['app' => $app]))->appUrl();
    }
}
