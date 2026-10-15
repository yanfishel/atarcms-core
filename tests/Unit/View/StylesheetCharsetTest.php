<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StylesheetCharsetTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /**
     * A stylesheet with characters beyond ASCII (the breadcrumbs' "›", the translations' "·") names its encoding
     * itself: a server that sends text/css without a charset, or a browser's cached guess, must not turn them into
     * "â€º".
     */
    #[DataProvider('stylesheets')]
    public function testAStylesheetBeyondAsciiNamesItsCharsetFirst(string $file): void
    {
        $css = (string) file_get_contents(self::ROOT . '/' . $file);

        $beyondAscii = preg_match('/[^\x00-\x7F]/', $css) === 1;

        self::assertTrue(
            !$beyondAscii || str_starts_with($css, "@charset \"UTF-8\";\n"),
            $file . ' has characters beyond ASCII but does not start with @charset "UTF-8";',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stylesheets(): iterable
    {
        foreach ([
            ...(glob(self::ROOT . '/resources/admin/assets/*.css') ?: []),
            ...(glob(self::ROOT . '/skeleton/themes/*/assets/css/*.css') ?: []),
            ...(glob(self::ROOT . '/resources/themes/*/assets/css/*.css') ?: []),
        ] as $path) {
            $file = substr($path, strlen(self::ROOT) + 1);
            yield $file => [$file];
        }
    }
}
