<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Doctor;

use AtarCms\Cli\Output;
use AtarCms\Doctor\ConfigChecks;
use AtarCms\Doctor\DoctorContext;
use AtarCms\Doctor\DoctorOptions;
use AtarCms\Doctor\Status;
use AtarCms\Kernel\ConsoleEntry;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Support\FakePlatform;
use AtarCms\Tests\Support\TempDirectory;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The doctor on copies of the skeleton's configuration with a .env of each kind: it examines a site that does not
 * boot, and bin/atar explains a boot failure instead of printing a stack trace.
 */
final class DoctorConfigTest extends TestCase
{
    use TempDirectory;

    private const string SKELETON = __DIR__ . '/../../../skeleton';

    private const string GOOD_ENV = "APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://example.com\nAPP_THEME=default\nMAIL_DSN=\n";

    private const array VARIABLES = ['APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_THEME', 'LOG_LEVEL', 'MAIL_DSN', 'MAIL_FROM'];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
    }

    public function testAProductionSiteWithAValidConfigPasses(): void
    {
        $site = $this->site(self::GOOD_ENV, withTheme: true);

        $results = $this->check($site);

        foreach ($results as $id => $status) {
            self::assertSame(Status::Ok, $status, $id);
        }
        self::assertSame(['config.env', 'config.boot', 'config.app', 'config.theme', 'config.admin_menu', 'cron'], array_keys($results));
    }

    public function testWithoutEnvAndWithoutTheVariablesNothingBoots(): void
    {
        $site = $this->site(null);

        $results = $this->check($site);

        self::assertSame(Status::Fail, $results['config.env']);
        self::assertSame(Status::Fail, $results['config.boot']);
        self::assertSame(Status::Skip, $results['config.app']);
        self::assertSame(Status::Skip, $results['config.theme']);
        self::assertSame(Status::Skip, $results['config.admin_menu']);
    }

    public function testAnInvalidUrlStopsTheBootWithItsReason(): void
    {
        $site = $this->site(str_replace('https://example.com', 'example.com/site', self::GOOD_ENV));

        $context = $this->context($site);
        $boot = array_values(array_filter((new ConfigChecks())->run($context), static fn($r): bool => $r->id === 'config.boot'))[0];

        self::assertSame(Status::Fail, $boot->status);
        self::assertStringContainsString('app.url must be the absolute http(s) URL', $boot->message);
    }

    public function testDebugAndPlainHttpAreRefusedInProduction(): void
    {
        self::assertSame(Status::Fail, $this->check($this->site(str_replace('APP_DEBUG=false', 'APP_DEBUG=true', self::GOOD_ENV)))['config.app']);
        self::assertSame(Status::Fail, $this->check($this->site(str_replace('https://', 'http://', self::GOOD_ENV)))['config.app']);
        self::assertSame(Status::Ok, $this->check($this->site(str_replace(['APP_ENV=production', 'https://example.com'], ['APP_ENV=local', 'http://localhost:8080'], self::GOOD_ENV)))['config.app']);
        self::assertSame(Status::Warn, $this->check($this->site(str_replace('APP_ENV=production', 'APP_ENV=staging', self::GOOD_ENV)))['config.app']);
    }

    public function testAMissingThemeFails(): void
    {
        $results = $this->check($this->site(str_replace('APP_THEME=default', 'APP_THEME=gone', self::GOOD_ENV)));

        self::assertSame(Status::Fail, $results['config.theme']);
    }

    /**
     * The icon's name pattern passes the boot, and only admin pages read the sprite: without this check the mistake
     * waits in the config and fails every admin page at once (the atarcms.com build hit it with "circle-help").
     */
    public function testAnIconMissingFromTheSpriteFailsTheAdminMenuCheck(): void
    {
        $site = $this->site(self::GOOD_ENV, withTheme: true);
        $collections = $site . '/config/collections.php';
        file_put_contents($collections, str_replace("->icon('align-left')", "->icon('circle-help')", (string) file_get_contents($collections)));

        $context = $this->context($site);
        self::assertNull($context->bootError(), 'the name passes the boot: only the sprite knows it is wrong');
        $menu = array_values(array_filter((new ConfigChecks())->run($context), static fn($r): bool => $r->id === 'config.admin_menu'))[0];

        self::assertSame(Status::Fail, $menu->status);
        self::assertStringContainsString('"circle-help"', $menu->message);
        self::assertStringContainsString('icons.svg', $menu->message);
        self::assertSame(Status::Ok, $this->check($this->site(self::GOOD_ENV, withTheme: true))['config.admin_menu'], 'the skeleton config passes');
    }

    public function testAnEnvFileOthersCanReadOnlyWarns(): void
    {
        $site = $this->site(self::GOOD_ENV, withTheme: true);
        $platform = new FakePlatform();
        $platform->modes[$site . '/.env'] = 0o644;

        $results = $this->check($site, $platform);

        self::assertSame(Status::Warn, $results['config.env']);

        // Docker Desktop mounts show every file as 0777 and chmod changes nothing there: no warning to fix.
        $platform->modes[$site . '/.env'] = 0o777;
        self::assertSame(Status::Ok, $this->check($site, $platform)['config.env']);
    }

    public function testBinAtarExplainsABootFailureAndStillRunsTheDoctor(): void
    {
        $site = $this->site(str_replace('https://example.com', '', self::GOOD_ENV));

        [$code, $out] = $this->console(new Paths($site, $site . '/storage'), ['migrate']);
        self::assertSame(1, $code);
        self::assertStringContainsString('The site does not boot: app.url must be', $out);
        self::assertStringContainsString('php bin/atar doctor', $out);
        self::assertStringNotContainsString('#0 ', $out, 'no stack trace');

        [$code, $out] = $this->console(new Paths($site, $site . '/storage'), ['doctor', '--json']);
        self::assertSame(1, $code);
        $report = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertFalse($report['ok']);
        self::assertIsArray($report['checks']);
        self::assertContains(['id' => 'config.boot', 'status' => 'fail'], array_map(static function (mixed $c): array {
            self::assertIsArray($c);

            return ['id' => $c['id'], 'status' => $c['status']];
        }, $report['checks']));
    }

    public function testDoctorHelpShowsTheUsageInsteadOfRunningTheChecks(): void
    {
        $site = $this->site(self::GOOD_ENV);

        foreach (['--help', '-h'] as $flag) {
            [$code, $out] = $this->console(new Paths($site, $site . '/storage'), ['doctor', $flag]);

            self::assertSame(0, $code, $out);
            self::assertMatchesRegularExpression('/^Check PHP, .*\nUsage: php bin\/atar doctor \[--json\].*\n$/', $out);
        }
    }

    public function testAHelpFlagAfterTheOptionsEndStillRunsTheDoctor(): void
    {
        $site = $this->site(str_replace('https://example.com', '', self::GOOD_ENV));

        [, $out] = $this->console(new Paths($site, $site . '/storage'), ['doctor', '--', '--help']);

        self::assertStringContainsString('AtarCMS doctor:', $out);
        self::assertStringNotContainsString('Usage:', $out);
    }

    public function testTheDoctorIsListedWithItsOptions(): void
    {
        $site = $this->site(self::GOOD_ENV);

        [$code, $out] = $this->console(new Paths($site, $site . '/storage'), ['list']);

        self::assertSame(0, $code, $out);
        self::assertMatchesRegularExpression('/\n  doctor\s+.*--http/', $out);
    }

    /**
     * @return array<string, Status> the last status per id
     */
    private function check(string $site, ?FakePlatform $platform = null): array
    {
        $statuses = [];
        foreach ((new ConfigChecks())->run($this->context($site, $platform)) as $result) {
            $statuses[$result->id] = $result->status;
        }

        return $statuses;
    }

    private function context(string $site, ?FakePlatform $platform = null): DoctorContext
    {
        return new DoctorContext(new Paths($site, $site . '/storage'), $platform ?? new FakePlatform(), new DoctorOptions());
    }

    /**
     * A copy of the skeleton's config/ (and src/ is autoloaded from the repository) with the given .env.
     */
    private function site(?string $env, bool $withTheme = false): string
    {
        $site = $this->tempDir() . '/site-' . bin2hex(random_bytes(3));
        foreach (glob(self::SKELETON . '/config/*.php') ?: [] as $file) {
            $this->writeFile(basename($site) . '/config/' . basename($file), (string) file_get_contents($file));
        }
        mkdir($site . '/storage', 0o777, true);
        if ($env !== null) {
            file_put_contents($site . '/.env', $env);
        }
        if ($withTheme) {
            $source = realpath(self::SKELETON . '/themes/default') ?: self::fail('no default theme');
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $this->writeFile(basename($site) . '/themes/default/' . substr($file->getPathname(), strlen($source) + 1), (string) file_get_contents($file->getPathname()));
                }
            }
        } else {
            mkdir($site . '/themes/default', 0o777, true);
        }

        return $site;
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private function console(Paths $paths, array $args): array
    {
        $stdout = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $stderr = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $code = ConsoleEntry::run($paths, $args, new Output($stdout, $stderr), new FakePlatform(), errorHandler: false);
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr)];
    }
}
