<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Cli\Output;
use AtarCms\Demo\DemoSeeder;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Paths;

/**
 * bin/atar theme:check on the skeleton site and on a copy of it with a broken theme.
 */
final class ThemeCheckCommandTest extends SkeletonTestCase
{
    public function testTheActiveThemePassesWithItsPages(): void
    {
        $this->app->container()->service(DemoSeeder::class)->seed(null);

        [$code, $out] = $this->console($this->app, ['theme:check']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString("\n200 /ru/news\n", $out);
        self::assertStringContainsString("\n404 /he/atar-theme-check-missing-page\n", $out);
        self::assertStringEndsWith("theme:check default: 0 errors, 0 warnings\n", $out);
    }

    public function testJsonForMachines(): void
    {
        [$code, $out] = $this->console($this->app, ['theme:check', 'default', '--json', '--no-render']);

        self::assertSame(0, $code);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['theme' => 'default', 'ok' => true, 'errors' => [], 'warnings' => [], 'info' => [], 'pages' => []], $report);
    }

    public function testAThemeWithErrorsFailsAndAMissingThemeIsItsOwnCode(): void
    {
        foreach (glob(self::SITE . '/config/*.php') ?: [] as $file) {
            $this->writeFile('site/config/' . basename($file), (string) file_get_contents($file));
        }
        $this->writeFile('site/themes/bad/show.php', "<p style=\"color: red\">x</p>\n");
        $site = Application::boot(new Paths($this->tempDir() . '/site', $this->tempDir() . '/storage'));

        [$code, $out] = $this->console($site, ['theme:check', 'bad', '--no-render']);

        self::assertSame(1, $code);
        self::assertStringContainsString("ERROR [sources] themes/bad/show.php:1 A style attribute breaks the CSP (style-src 'self'): use a class in the stylesheet\n", $out);
        self::assertMatchesRegularExpression('/theme:check bad: \d+ errors, \d+ warnings\n$/', $out);

        [$code, $out] = $this->console($site, ['theme:check', 'missing']);
        self::assertSame(2, $code);
        self::assertStringContainsString('No theme directory themes/missing', $out);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private function console(Application $app, array $argv): array
    {
        $stdout = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $stderr = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $code = $app->console()->run($argv, new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr)];
    }
}
