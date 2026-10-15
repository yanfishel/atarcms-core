<?php

declare(strict_types=1);

namespace AtarCms\Auth\Http;

use AtarCms\Auth\Avatar\Avatars;
use AtarCms\Http\Exception\NotFound;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /admin/avatar/{id}: a user's profile photo for any signed-in user (the top bar's avatar). Its URL carries the
 * photo's version (?v=), so it is cached privately for a year and a new photo shows at once.
 */
final class AvatarController
{
    private const string CACHE = 'private, max-age=31536000, immutable';

    public function __construct(private readonly Avatars $avatars) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');
        $file = is_string($id) ? $this->avatars->path((int) $id) : '';
        $bytes = is_file($file) ? file_get_contents($file) : false;
        if ($bytes === false || $bytes === '') {
            throw new NotFound('No such avatar');
        }

        return new Response(200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => self::CACHE,
            'X-Content-Type-Options' => 'nosniff',
        ], $bytes);
    }
}
