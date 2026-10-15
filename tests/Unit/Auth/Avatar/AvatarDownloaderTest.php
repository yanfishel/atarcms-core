<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth\Avatar;

use AtarCms\Auth\Avatar\AvatarDownloader;
use AtarCms\Auth\Avatar\AvatarFailed;
use AtarCms\Tests\Support\FakeHttpClient;
use AtarCms\Tests\Support\TestImages;
use AtarCms\Tests\Support\TricklingStream;
use GuzzleHttp\RequestOptions;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvatarDownloaderTest extends TestCase
{
    private const string URL = 'https://lh3.googleusercontent.com/a/ACg8ocK=s96-c';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'a profile photo' => [self::URL, true];
        yield 'the bare domain' => ['https://googleusercontent.com/a/x', true];
        yield 'upper case host' => ['https://LH3.GoogleUserContent.com/a/x', true];
        yield 'plain http' => ['http://lh3.googleusercontent.com/a/x', false];
        yield 'a look-alike domain' => ['https://evilgoogleusercontent.com/a/x', false];
        yield 'a suffix domain' => ['https://lh3.googleusercontent.com.evil.example/a/x', false];
        yield 'credentials before the host' => ['https://googleusercontent.com@evil.example/a/x', false];
        yield 'another port' => ['https://lh3.googleusercontent.com:8443/a/x', false];
        yield 'no host' => ['https:///a/x', false];
        yield 'not a URL' => ['data:image/png;base64,AAAA', false];
    }

    #[DataProvider('urls')]
    public function testOnlyGoogleProfilePhotoAddressesAreAllowed(string $url, bool $allowed): void
    {
        self::assertSame($allowed, AvatarDownloader::isAllowed($url));
    }

    public function testThePhotoIsFetchedWithAGet(): void
    {
        $photo = TestImages::jpeg(96, 96);
        $client = new FakeHttpClient(new Response(200, ['Content-Type' => 'image/jpeg'], $photo));

        self::assertSame($photo, $this->downloader($client)->download(self::URL));
        self::assertCount(1, $client->requests);
        self::assertSame('GET', $client->requests[0]->getMethod());
        self::assertSame(self::URL, (string) $client->requests[0]->getUri());
    }

    public function testAnAddressOutsideTheAllowListIsNeverRequested(): void
    {
        $client = new FakeHttpClient();

        $this->expectException(AvatarFailed::class);
        try {
            $this->downloader($client)->download('https://evil.example/a.png');
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    public function testANetworkErrorFails(): void
    {
        $this->expectException(AvatarFailed::class);
        $this->expectExceptionMessage('Connection timed out');

        $this->downloader(new FakeHttpClient(FakeHttpClient::networkError()))->download(self::URL);
    }

    public function testAnErrorStatusFails(): void
    {
        $this->expectException(AvatarFailed::class);
        $this->expectExceptionMessage('404');

        $this->downloader(new FakeHttpClient(new Response(404)))->download(self::URL);
    }

    public function testADeclaredSizeOverTheLimitFailsWithoutReading(): void
    {
        $this->expectException(AvatarFailed::class);

        $this->downloader(new FakeHttpClient(new Response(200, ['Content-Length' => '101'], 'x')), 100)->download(self::URL);
    }

    public function testABodyOverTheLimitFails(): void
    {
        $this->expectException(AvatarFailed::class);

        $this->downloader(new FakeHttpClient(new Response(200, [], str_repeat('x', 101))), 100)->download(self::URL);
    }

    public function testAnEmptyBodyFails(): void
    {
        $this->expectException(AvatarFailed::class);

        $this->downloader(new FakeHttpClient(new Response(200, [], '')))->download(self::URL);
    }

    public function testARedirectIsRefusedAndNotFollowed(): void
    {
        $client = new FakeHttpClient(new Response(302, ['Location' => 'https://evil.example/photo.png']), new Response(200, [], 'never'));

        try {
            $this->downloader($client)->download(self::URL);
            self::fail('The redirect was accepted');
        } catch (AvatarFailed $e) {
            self::assertStringContainsString('302', $e->getMessage());
        }
        self::assertCount(1, $client->requests);
    }

    public function testATricklingBodyStopsAtTheDeadline(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        // Each chunk arrives a second later: within every read timeout, yet never-ending as a whole.
        $body = new TricklingStream(static function () use (&$now): void {
            $now += 1.0;
        });
        $downloader = new AvatarDownloader(new FakeHttpClient(new Response(200, [], $body)), new Psr17Factory(), AvatarDownloader::MAX_BYTES, 5.0, $clock);

        try {
            $downloader->download(self::URL);
            self::fail('The trickle was accepted');
        } catch (AvatarFailed $e) {
            self::assertStringContainsString('5 seconds', $e->getMessage());
        }
        self::assertLessThanOrEqual(6.0, $now, 'it stops right after the deadline');
    }

    public function testTheProductionClientNeitherRedirectsNorDecodesAndHasTimeouts(): void
    {
        $client = AvatarDownloader::client();

        self::assertSame(5, $client->getConfig(RequestOptions::TIMEOUT));
        self::assertSame(3, $client->getConfig(RequestOptions::CONNECT_TIMEOUT));
        self::assertSame(3, $client->getConfig(RequestOptions::READ_TIMEOUT), 'the stream handler applies no overall timeout to a streamed body');
        self::assertTrue($client->getConfig(RequestOptions::STREAM));
        self::assertFalse($client->getConfig(RequestOptions::DECODE_CONTENT), 'the size limit counts the bytes on the wire');
        self::assertFalse($client->getConfig(RequestOptions::ALLOW_REDIRECTS));
    }

    private function downloader(FakeHttpClient $client, int $maxBytes = AvatarDownloader::MAX_BYTES): AvatarDownloader
    {
        return new AvatarDownloader($client, new Psr17Factory(), $maxBytes);
    }
}
