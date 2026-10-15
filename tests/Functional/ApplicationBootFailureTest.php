<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Kernel\Application;
use AtarCms\Tests\Support\TempDirectory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * Boot/service resolution failures (here: an invalid `log_level` that JsonFileLogger rejects)
 * must never surface as a blank, unlogged 500. Application::handle() must catch them and
 * return a plain error page, and log the failure via error_log().
 */
final class ApplicationBootFailureTest extends TestCase
{
    use TempDirectory;

    public function testHandleReturnsPlainServerErrorAndLogsWhenServiceResolutionFails(): void
    {
        $site = $this->brokenSite();

        $previousErrorLog = ini_get('error_log');
        $errorLogFile = $this->tempDir() . '/php-error.log';
        ini_set('error_log', $errorLogFile);

        try {
            $response = Application::fromSite($site)->handle(
                (new Psr17Factory())->createServerRequest('GET', '/'),
            );

            self::assertSame(500, $response->getStatusCode());
            self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString('<h1>500 Server Error</h1>', (string) $response->getBody());

            $log = is_file($errorLogFile) ? (string) file_get_contents($errorLogFile) : '';
            self::assertStringContainsString('AtarCMS request failed', $log);
        } finally {
            ini_set('error_log', $previousErrorLog);
        }
    }

    private function brokenSite(): string
    {
        $site = $this->tempDir() . '/site';

        $this->writeFile('site/config/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use AtarCms\Tests\Fixtures\FixtureModule;

            return [
                'theme' => 'test',
                'url' => 'https://localhost',
                'log_level' => 'warn',
                'modules' => [FixtureModule::class],
            ];
            PHP);

        // The theme only needs to exist; this scenario fails while building the
        // middleware pipeline, before any template is ever resolved or rendered.
        mkdir($site . '/themes/test', 0777, true);

        return $site;
    }
}
