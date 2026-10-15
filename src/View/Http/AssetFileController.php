<?php

declare(strict_types=1);

namespace AtarCms\View\Http;

use AtarCms\Http\Exception\NotFound;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Paths;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The PHP side of /theme-assets/{path} (the active theme's assets/) and /admin-assets/{path} (the core's admin
 * assets). Web servers with the deploy configs serve both directories themselves with the same headers; this keeps
 * every other server correct: Apache with only .htaccess, shared hosting whose document root holds nothing but
 * public/, php -S. Only files of an allowed type inside the asset directory are served; their URLs carry a content
 * hash (AssetUrl), so they are immutable.
 */
final class AssetFileController
{
    /** Keep in sync with deploy/nginx, deploy/caddy and deploy/apache (DeployConfigTest). */
    public const string CACHE = 'public, max-age=31536000, immutable';

    /** Path segments that cannot be "..", hidden files or anything but plain names. */
    public const string PATH_PATTERN = '(?:[A-Za-z0-9_][A-Za-z0-9_.-]*/)*[A-Za-z0-9_][A-Za-z0-9_.-]*';

    /** @var array<string, string> */
    private const array TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'map' => 'application/json',
        'json' => 'application/json',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'ico' => 'image/vnd.microsoft.icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
    ];

    public function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
        private readonly Psr17Factory $factory,
    ) {}

    public static function adminDirectory(): string
    {
        return dirname(__DIR__, 3) . '/resources/admin/assets';
    }

    public function theme(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serve($this->paths->theme($this->config->string('app.theme')) . '/assets', $request);
    }

    public function admin(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serve(self::adminDirectory(), $request);
    }

    private function serve(string $directory, ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('path');
        if (!is_string($path) || preg_match('~^' . self::PATH_PATTERN . '$~D', $path) !== 1) {
            throw new NotFound('No such asset');
        }
        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        $root = realpath($directory);
        $file = realpath($directory . '/' . $path);
        if ($type === null || $root === false || $file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw new NotFound('No such asset');
        }
        $etag = '"' . hash_file('xxh128', $file) . '"';
        $response = $this->factory->createResponse(200)
            ->withHeader('Cache-Control', self::CACHE)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('ETag', $etag);
        if (in_array($etag, array_map(trim(...), explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
            return $response->withStatus(304);
        }

        return $response
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Length', (string) filesize($file))
            ->withBody($this->factory->createStreamFromFile($file));
    }
}
