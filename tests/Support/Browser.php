<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use AtarCms\Kernel\Application;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Sends in-memory requests through the full application and keeps cookies between them.
 */
final class Browser
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly Application $app) {}

    /**
     * @param array<string, string> $headers
     */
    public function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->send('GET', $uri, null, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->send('POST', $uri, $data, $headers);
    }

    /**
     * A multipart POST; $files maps field names to one uploaded file or a list of them ("files" => [...] for files[]).
     *
     * @param array<string, mixed> $data
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files
     * @param array<string, string> $headers
     */
    public function upload(string $uri, array $data, array $files, array $headers = []): ResponseInterface
    {
        return $this->send('POST', $uri, $data, $headers, $files);
    }

    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, string> $headers
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files
     */
    private function send(string $method, string $uri, ?array $data, array $headers, array $files = []): ResponseInterface
    {
        $url = 'https://localhost' . $uri;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $request = (new Psr17Factory())
            ->createServerRequest($method, $url, ['REMOTE_ADDR' => '203.0.113.7'])
            ->withCookieParams($this->cookies)
            ->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($data !== null) {
            $request = $request->withParsedBody($data)->withHeader(
                'Content-Type',
                $files === [] ? 'application/x-www-form-urlencoded' : 'multipart/form-data; boundary=atar-test',
            );
        }
        if ($files !== []) {
            $request = $request->withUploadedFiles($files);
        }

        $response = $this->app->handle($request);

        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ($value === '' || str_contains($header, 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }
}
