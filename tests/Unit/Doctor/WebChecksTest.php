<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Doctor;

use AtarCms\Doctor\CheckResult;
use AtarCms\Doctor\DoctorContext;
use AtarCms\Doctor\DoctorOptions;
use AtarCms\Doctor\Status;
use AtarCms\Doctor\WebChecks;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Support\FakePlatform;
use AtarCms\Tests\Support\TempDirectory;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * doctor --http against a fake web server: what it serves, what it must never serve, and what its PHP says about
 * itself through the one-time probe.
 */
final class WebChecksTest extends TestCase
{
    use TempDirectory;

    private const string SITE = __DIR__ . '/../../Fixtures/site';

    /** @var array<string, Response> path => response; others answer the site's 404 */
    private array $pages = [];

    /**
     * The facts the probe answers with; [] answers the site's 404.
     *
     * @var array{php?: string, sapi?: string, extensions?: array<string, bool>, ini?: array<string, string>, gd?: array<string, bool>, months?: array<string, string>, uid?: int, user?: string, storage?: array<string, bool>, app_key?: string, session?: bool}
     */
    private array $facts;

    /** @var list<string> */
    private array $requested = [];

    private FakePlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new FakePlatform();
        $this->pages = [
            '/' => new Response(200, ['Strict-Transport-Security' => 'max-age=31536000']),
            '/admin-assets/admin.css' => new Response(200, ['Cache-Control' => 'public, max-age=31536000, immutable']),
            '/theme-assets/app.css' => new Response(200),
            '/favicon.ico' => new Response(302),
            '/robots.txt' => new Response(200),
        ];
        $this->facts = [
            'php' => '8.4.12', 'sapi' => 'fpm-fcgi',
            'extensions' => array_fill_keys(['pdo_mysql', 'intl', 'mbstring', 'fileinfo', 'gd', 'exif', 'dom', 'json', 'ctype', 'iconv', 'openssl'], true),
            'ini' => ['memory_limit' => '256M', 'upload_max_filesize' => '100M', 'post_max_size' => '101M', 'allow_url_fopen' => '1', 'opcache.enable' => '1'],
            'gd' => ['JPEG Support' => true, 'PNG Support' => true, 'WebP Support' => true, 'GIF Read Support' => true, 'FreeType Support' => true, 'AVIF Support' => true],
            'months' => ['en' => 'January', 'ru' => 'январь'],
            'uid' => 1000, 'user' => 'site',
            'storage' => ['storage' => true, 'storage/logs' => true],
            'app_key' => 'readable', 'session' => true,
        ];
    }

    public function testWithoutHttpNothingIsFetched(): void
    {
        $results = $this->results(new DoctorOptions());

        self::assertSame([], $this->requested);
        self::assertSame(Status::Skip, $results['web']->status);
    }

    public function testAWellServedSitePasses(): void
    {
        $results = $this->results();

        foreach ($results as $id => $result) {
            self::assertSame(Status::Ok, $result->status, $id . ': ' . $result->message);
        }
        self::assertArrayHasKey('web.php.version', $results);
        self::assertArrayHasKey('web.intl.icu', $results);
        self::assertArrayHasKey('web.user', $results);
    }

    public function testAnExposedFileFails(): void
    {
        $this->pages['/.env'] = new Response(200, [], "APP_URL=…\n");
        $this->pages['/vendor/autoload.php'] = new Response(200);

        $result = $this->results()['web.exposure'];

        self::assertSame(Status::Fail, $result->status);
        self::assertStringContainsString('/.env', $result->message);
        self::assertStringContainsString('/vendor/autoload.php', $result->message);
        self::assertStringContainsString('public/', (string) $result->fix);
    }

    public function testProductionWantsHsts(): void
    {
        $this->pages['/'] = new Response(200);

        self::assertSame(Status::Warn, $this->results()['web.https']->status);
    }

    public function testMissingAssetsFail(): void
    {
        unset($this->pages['/theme-assets/app.css']);

        self::assertSame(Status::Fail, $this->results()['web.assets']->status);
    }

    public function testTheWebPhpIsJudgedLikeTheCli(): void
    {
        $this->facts['php'] = '8.3.20';
        $this->facts['ini']['upload_max_filesize'] = '2M';
        $this->facts['months']['ru'] = 'M01';

        $results = $this->results();

        self::assertSame(Status::Fail, $results['web.php.version']->status);
        self::assertSame(Status::Fail, $results['web.php.uploads']->status);
        self::assertSame(Status::Fail, $results['web.intl.icu']->status);
        self::assertStringStartsWith('web server: ', $results['web.php.version']->message);
    }

    public function testTheWebUserMustOwnStorage(): void
    {
        $this->facts['uid'] = 33;
        $this->facts['user'] = 'www-data';

        $result = $this->results()['web.user'];

        self::assertSame(Status::Fail, $result->status);
        self::assertStringContainsString('www-data', $result->message);
        self::assertStringContainsString('chown -R www-data: storage', (string) $result->fix);
    }

    public function testStorageTheWebCannotWriteFails(): void
    {
        $this->facts['storage']['storage/logs'] = false;
        $this->facts['app_key'] = 'unreadable';

        $result = $this->results()['web.storage'];

        self::assertSame(Status::Fail, $result->status);
        self::assertStringContainsString('storage/logs', $result->message);
        self::assertStringContainsString('app.key', $result->message);
    }

    public function testOpcacheOffWarnsInProduction(): void
    {
        $this->facts['ini']['opcache.enable'] = '0';

        self::assertSame(Status::Warn, $this->results()['web.opcache']->status);
    }

    public function testTheProbeIsNotWrittenByRootOrAnotherUser(): void
    {
        // A token file (or storage/tmp) made by root would break the site: the probe is skipped instead.
        $this->platform->uid = 0;
        $results = $this->results();
        self::assertSame(Status::Skip, $results['web.probe']->status);
        self::assertStringContainsString('site', $results['web.probe']->message);
        self::assertSame([], array_values(array_filter($this->requested, static fn(string $p): bool => str_starts_with($p, '/_atar/'))));

        $this->platform->uid = 33;
        self::assertSame(Status::Skip, $this->results()['web.probe']->status);
    }

    public function testExposureCoversTheThemeAndGit(): void
    {
        $this->pages['/.git/config'] = new Response(200);
        $this->pages['/themes/test/theme.php'] = new Response(200);

        $message = $this->results()['web.exposure']->message;

        self::assertStringContainsString('/.git/config', $message);
        self::assertStringContainsString('/themes/test/theme.php', $message);
    }

    public function testAProbeThatDoesNotAnswerFails(): void
    {
        $this->facts = [];

        $result = $this->results()['web.probe'];

        self::assertSame(Status::Fail, $result->status);
        self::assertStringContainsString('another directory', $result->message);
    }

    public function testAnUnreachableSiteFailsOnceAndSkipsTheRest(): void
    {
        $this->pages = [];

        $results = $this->results(new DoctorOptions(http: true), unreachable: true);

        self::assertSame(Status::Fail, $results['web.home']->status);
        self::assertStringContainsString('cannot connect', $results['web.home']->message);
        self::assertSame(Status::Skip, $results['web.exposure']->status);
    }

    /**
     * @return array<string, CheckResult>
     */
    private function results(?DoctorOptions $options = null, bool $unreachable = false): array
    {
        $handler = fn(RequestInterface $request, array $options): PromiseInterface => $this->answer($request, $unreachable);
        mkdir($this->tempDir() . '/storage', 0o777, true);
        $context = new DoctorContext(new Paths(self::SITE, $this->tempDir() . '/storage'), $this->platform, $options ?? new DoctorOptions(http: true));
        $byId = [];
        foreach ((new WebChecks($handler))->run($context) as $result) {
            $byId[$result->id] = $result;
        }
        $this->removeTempDir();

        return $byId;
    }

    /**
     * The Guzzle handler's answer: the probe's facts, a page, or a connection error.
     *
     * @return PromiseInterface<covariant mixed, covariant mixed>
     */
    private function answer(RequestInterface $request, bool $unreachable): PromiseInterface
    {
        $path = $request->getUri()->getPath();
        $this->requested[] = $path;
        if ($unreachable) {
            return Create::rejectionFor(new ConnectException('Connection refused', $request));
        }
        if (str_starts_with($path, '/_atar/doctor/')) {
            return Create::promiseFor($this->facts === [] ? new Response(404) : new Response(200, ['Content-Type' => 'application/json'], json_encode($this->facts, JSON_THROW_ON_ERROR)));
        }

        return Create::promiseFor($this->pages[$path] ?? new Response(404));
    }
}
