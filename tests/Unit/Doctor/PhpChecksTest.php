<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Doctor;

use AtarCms\Doctor\CheckResult;
use AtarCms\Doctor\DoctorContext;
use AtarCms\Doctor\DoctorOptions;
use AtarCms\Doctor\PhpChecks;
use AtarCms\Doctor\Status;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Support\FakePlatform;
use AtarCms\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class PhpChecksTest extends TestCase
{
    use TempDirectory;

    private const string SITE = __DIR__ . '/../../Fixtures/site';

    private FakePlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new FakePlatform();
        putenv('APP_URL=https://localhost');
    }

    protected function tearDown(): void
    {
        putenv('APP_URL');
        putenv('MAIL_DSN');
    }

    public function testAHealthyPhpPassesEveryCheck(): void
    {
        $results = $this->results();

        foreach ($results as $result) {
            self::assertSame(Status::Ok, $result->status, $result->id . ': ' . $result->message);
        }
        self::assertSame(['php.version', 'php.extensions', 'php.openssl', 'php.url_fopen', 'intl.icu', 'gd.formats', 'gd.avif', 'php.memory', 'php.uploads'], array_values(array_unique(array_map(static fn(CheckResult $r): string => $r->id, $results))));
    }

    public function testPhpBelow84Fails(): void
    {
        $this->platform->version = '8.3.9';

        self::assertSame(Status::Fail, $this->one('php.version')->status);
    }

    public function testMissingExtensionsAreNamedWithTheirPackages(): void
    {
        $this->platform->missingExtensions = ['intl', 'pdo_mysql', 'dom'];

        $result = $this->one('php.extensions');

        self::assertSame(Status::Fail, $result->status);
        self::assertStringContainsString('pdo_mysql, intl, dom', $result->message);
        self::assertStringContainsString('php8.4-mysql php8.4-intl php8.4-xml', (string) $result->fix);
        self::assertSame(Status::Skip, $this->one('intl.icu')->status, 'no ICU check without intl');
    }

    public function testOpensslIsRequiredOnlyWhenMailOrGoogleNeedIt(): void
    {
        $this->platform->missingExtensions = ['openssl'];
        self::assertSame(Status::Warn, $this->one('php.openssl')->status);

        putenv('MAIL_DSN=smtp://mail.example.com:587');
        self::assertSame(Status::Fail, $this->one('php.openssl')->status);
    }

    public function testUrlFopenOffOnlyWarns(): void
    {
        $this->platform->ini['allow_url_fopen'] = '';

        self::assertSame(Status::Warn, $this->one('php.url_fopen')->status);
    }

    public function testEveryConfiguredLocaleNeedsIcuData(): void
    {
        unset($this->platform->months['ru']);

        $results = array_values(array_filter($this->results(), static fn(CheckResult $r): bool => $r->id === 'intl.icu'));

        self::assertCount(1, array_filter($results, static fn(CheckResult $r): bool => $r->status === Status::Fail));
        $failure = array_values(array_filter($results, static fn(CheckResult $r): bool => $r->status === Status::Fail))[0];
        self::assertStringContainsString('"ru"', $failure->message);
        self::assertStringContainsString('icu-data-full', (string) $failure->fix);
    }

    public function testALanguageThatFallsBackToEnglishHasNoIcuData(): void
    {
        $this->platform->months['ru'] = 'January';

        self::assertContains(Status::Fail, array_map(static fn(CheckResult $r): Status => $r->status, $this->all('intl.icu')));
    }

    public function testGdNeedsEveryUploadFormatAndFreeTypeForShareCards(): void
    {
        $this->platform->gd['WebP Support'] = false;
        self::assertSame(Status::Fail, $this->one('gd.formats')->status);

        $this->platform->gd['WebP Support'] = true;
        $this->platform->gd['FreeType Support'] = false;
        $result = $this->one('gd.formats');
        self::assertSame(Status::Warn, $result->status);
        self::assertStringContainsString('share', $result->message);
    }

    public function testAGdWithoutAvifOnlyWarns(): void
    {
        self::assertSame(Status::Ok, $this->one('gd.avif')->status);

        unset($this->platform->gd['AVIF Support']);
        $result = $this->one('gd.avif');

        self::assertSame(Status::Warn, $result->status);
        self::assertStringContainsString('AVIF', $result->message);
        self::assertStringContainsString('--with-avif', (string) $result->fix);
        self::assertSame(Status::Ok, $this->one('gd.formats')->status, 'the upload formats do not need AVIF');

        $this->platform->gd['AVIF Support'] = true;
        $this->platform->gd['AVIF Encodes'] = false;
        $broken = $this->one('gd.avif');
        self::assertSame(Status::Warn, $broken->status, 'built with AVIF, but the encoder fails');
        self::assertStringContainsString('cannot encode', $broken->message);

        $this->platform->gd = [];
        self::assertSame(Status::Skip, $this->one('gd.avif')->status);
    }

    public function testAvifTurnedOffInTheConfigNeedsNoGdSupport(): void
    {
        $site = $this->tempDir();
        $this->writeFile('.env', "APP_URL=https://localhost\n");
        $this->writeFile('config/app.php', "<?php return ['url' => 'https://localhost'];\n");
        $this->writeFile('config/media.php', "<?php return ['avif' => false];\n");
        $this->platform->gd['AVIF Support'] = false;

        $results = array_values(array_filter(
            new PhpChecks()->run(new DoctorContext(new Paths($site), $this->platform, new DoctorOptions())),
            static fn(CheckResult $r): bool => $r->id === 'gd.avif',
        ));

        self::assertCount(1, $results);
        self::assertSame(Status::Ok, $results[0]->status);
        self::assertStringContainsString('media.avif', $results[0]->message);
    }

    public function testMemoryLimitAgainstThePixelLimit(): void
    {
        // The fixture's media config: 25 MP → at least 25 000 000 × 5 + 64 MiB.
        foreach (['-1' => Status::Ok, '1G' => Status::Ok, '256M' => Status::Ok, '200M' => Status::Warn, '128M' => Status::Fail, '134217728' => Status::Fail] as $limit => $status) {
            $this->platform->ini['memory_limit'] = (string) $limit;
            self::assertSame($status, $this->one('php.memory')->status, (string) $limit);
        }
    }

    public function testUploadLimitsOfTheWebServerAgainstTheMediaLimit(): void
    {
        $this->platform->ini['upload_max_filesize'] = '2M';
        $result = $this->one('php.uploads', web: true);
        self::assertSame(Status::Fail, $result->status);
        self::assertStringContainsString('upload_max_filesize', (string) $result->fix);

        $this->platform->ini['upload_max_filesize'] = '20M';
        $this->platform->ini['post_max_size'] = '8M';
        self::assertSame(Status::Fail, $this->one('php.uploads', web: true)->status);
    }

    public function testAnUploadLimitBelowTheVideoLimitOnlyWarns(): void
    {
        // The fixture allows videos (the default types) up to 100 MB; 20M takes every other file.
        $this->platform->ini['upload_max_filesize'] = '20M';
        $this->platform->ini['post_max_size'] = '21M';

        $result = $this->one('php.uploads', web: true);

        self::assertSame(Status::Warn, $result->status);
        self::assertStringContainsString('below the video limit 100M', $result->message);
        self::assertStringContainsString('client_max_body_size', (string) $result->fix);
        self::assertStringContainsString('media.max_video_size', (string) $result->fix);
    }

    public function testTheCommandLinesUploadLimitsOnlyWarn(): void
    {
        // Debian's CLI php.ini keeps 2M while the PHP-FPM pool sets 20M: uploads never go through the CLI.
        $this->platform->ini['upload_max_filesize'] = '2M';

        $result = $this->one('php.uploads');

        self::assertSame(Status::Warn, $result->status);
        self::assertStringContainsString('doctor --http', (string) $result->fix);
    }

    /**
     * @return list<CheckResult>
     */
    private function results(bool $web = false): array
    {
        return (new PhpChecks($web))->run(new DoctorContext(new Paths(self::SITE), $this->platform, new DoctorOptions()));
    }

    /**
     * @return list<CheckResult>
     */
    private function all(string $id, bool $web = false): array
    {
        return array_values(array_filter($this->results($web), static fn(CheckResult $r): bool => $r->id === $id));
    }

    private function one(string $id, bool $web = false): CheckResult
    {
        $results = $this->all($id, $web);
        self::assertCount(1, $results, $id);

        return $results[0];
    }
}
