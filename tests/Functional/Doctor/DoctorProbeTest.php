<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Doctor;

use AtarCms\Doctor\WebProbe;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Functional\FunctionalTestCase;

/**
 * The web server's PHP answers `doctor --http` once, for the token bin/atar just wrote; any other request is a 404.
 */
final class DoctorProbeTest extends FunctionalTestCase
{
    private Paths $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paths = $this->app->container()->service(Paths::class);
        @unlink($this->paths->storage(WebProbe::FILE));
    }

    protected function tearDown(): void
    {
        @unlink($this->paths->storage(WebProbe::FILE));
        parent::tearDown();
    }

    public function testTheIssuedTokenAnswersOnceWithFactsOnly(): void
    {
        $token = WebProbe::issue($this->paths, time());

        $response = $this->browser->get('/_atar/doctor/' . $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        $facts = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($facts);
        self::assertSame(['php', 'sapi', 'extensions', 'ini', 'gd', 'months', 'uid', 'user', 'storage', 'app_key', 'session'], array_keys($facts));
        self::assertSame(PHP_VERSION, $facts['php']);
        self::assertIsArray($facts['months']);
        self::assertSame('январь', $facts['months']['ru']);
        self::assertStringNotContainsString($this->paths->base, (string) $response->getBody(), 'no paths');
        self::assertFileDoesNotExist($this->paths->storage(WebProbe::FILE));

        self::assertSame(404, $this->browser->get('/_atar/doctor/' . $token)->getStatusCode(), 'single use');
    }

    public function testAWrongOrExpiredTokenIsANormal404(): void
    {
        $token = WebProbe::issue($this->paths, time());
        self::assertSame(404, $this->browser->get('/_atar/doctor/' . str_repeat('0', 64))->getStatusCode());
        self::assertSame(404, $this->browser->get('/_atar/doctor/' . strtoupper($token))->getStatusCode());
        self::assertSame(404, $this->browser->get('/_atar/doctor')->getStatusCode());

        $expired = WebProbe::issue($this->paths, time() - WebProbe::LIFETIME - 5);
        self::assertSame(404, $this->browser->get('/_atar/doctor/' . $expired)->getStatusCode());

        @unlink($this->paths->storage(WebProbe::FILE));
        self::assertSame(404, $this->browser->get('/_atar/doctor/' . $token)->getStatusCode(), 'no file, no answer');
    }
}
