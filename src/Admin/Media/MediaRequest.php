<?php

declare(strict_types=1);

namespace AtarCms\Admin\Media;

use AtarCms\Http\Exception\NotFound;
use AtarCms\Media\Media;
use AtarCms\Media\MediaRepository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the medium named in an admin URL; an unknown id is a 404.
 */
final class MediaRequest
{
    public function __construct(private readonly MediaRepository $media) {}

    public function media(ServerRequestInterface $request): Media
    {
        $id = $request->getAttribute('id');
        $media = is_string($id) && strlen($id) <= 10 ? $this->media->find((int) $id) : null;

        return $media ?? throw new NotFound(sprintf('No media #%s', is_string($id) ? $id : '?'));
    }
}
