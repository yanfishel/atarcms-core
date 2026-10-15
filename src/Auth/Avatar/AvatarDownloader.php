<?php

declare(strict_types=1);

namespace AtarCms\Auth\Avatar;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use RuntimeException;

/**
 * Downloads a Google profile photo (the ID token's "picture" claim): only https addresses on googleusercontent.com
 * and its subdomains are requested, no redirect is followed (PSR-18), a body over the size limit (wire bytes: the
 * client does not decode) is refused, and the whole download stops at a wall-clock deadline: a streamed body gets no
 * overall timeout from Guzzle's stream handler, only the read timeout per read.
 */
final class AvatarDownloader
{
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** The whole download, from the request to the last byte. */
    public const float DEADLINE_SECONDS = 5.0;

    private const string DOMAIN = 'googleusercontent.com';

    private const int CHUNK = 65536;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param (Closure(): float)|null $clock seconds of a monotonic clock (hrtime() by default)
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly float $deadline = self::DEADLINE_SECONDS,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * The production client: short timeouts (the read timeout per read of the streamed body), the body streamed so
     * download() can stop at its limits, no redirects, no content decoding.
     */
    public static function client(): Client
    {
        return new Client([
            RequestOptions::TIMEOUT => 5,
            RequestOptions::CONNECT_TIMEOUT => 3,
            RequestOptions::READ_TIMEOUT => 3,
            RequestOptions::STREAM => true,
            RequestOptions::DECODE_CONTENT => false,
            RequestOptions::ALLOW_REDIRECTS => false,
        ]);
    }

    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        return $host === self::DOMAIN || str_ends_with($host, '.' . self::DOMAIN);
    }

    /**
     * The photo's bytes, unchecked: Avatars::save() decides whether they are an image.
     *
     * @throws AvatarFailed
     */
    public function download(string $url): string
    {
        if (!self::isAllowed($url)) {
            throw new AvatarFailed(sprintf('The photo address "%s" is not a Google profile photo', $url));
        }
        $until = ($this->clock)() + $this->deadline;
        try {
            $response = $this->client->sendRequest($this->requests->createRequest('GET', $url)->withHeader('Accept', 'image/*'));
        } catch (ClientExceptionInterface $e) {
            throw new AvatarFailed('The photo could not be downloaded: ' . $e->getMessage(), 0, $e);
        }
        $body = $response->getBody();
        try {
            if ($response->getStatusCode() !== 200) {
                throw new AvatarFailed(sprintf('The photo download answered %d', $response->getStatusCode()));
            }
            $declared = $response->getHeaderLine('Content-Length');
            if ($declared !== '' && (int) $declared > $this->maxBytes) {
                throw new AvatarFailed(sprintf('The photo is larger than %d bytes', $this->maxBytes));
            }
            $bytes = '';
            while (!$body->eof()) {
                if (($this->clock)() > $until) {
                    throw new AvatarFailed(sprintf('The photo download took longer than %d seconds', (int) $this->deadline));
                }
                try {
                    $chunk = $body->read(self::CHUNK);
                } catch (RuntimeException $e) {
                    throw new AvatarFailed('The photo could not be read: ' . $e->getMessage(), 0, $e);
                }
                if ($chunk === '') {
                    break;
                }
                $bytes .= $chunk;
                if (strlen($bytes) > $this->maxBytes) {
                    throw new AvatarFailed(sprintf('The photo is larger than %d bytes', $this->maxBytes));
                }
            }
            if (($this->clock)() > $until) {
                throw new AvatarFailed(sprintf('The photo download took longer than %d seconds', (int) $this->deadline));
            }
        } finally {
            $body->close();
        }
        if ($bytes === '') {
            throw new AvatarFailed('The photo download was empty');
        }

        return $bytes;
    }
}
