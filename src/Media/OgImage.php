<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Content\Entry;
use AtarCms\Content\ShareImage\ShareImages;
use AtarCms\Http\RequestMemo;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The absolute URL of an entry's sharing image, used by the ogImage() and socialMeta() theme helpers: its generated
 * share image (ShareImages), else its main photo; and of the site's default sharing image (the site settings).
 */
final class OgImage
{
    /** The size of a generated share image, for og:image:width and og:image:height. */
    public const array GENERATED_SIZE = [1200, 630];

    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaUrls $urls,
        private readonly ShareImages $shareImages,
    ) {}

    /**
     * The URL of the entry's generated share image, else of $variant of its main photo (the original when null), or
     * null when it has neither. A generated image costs no query.
     */
    public function url(?ServerRequestInterface $request, Entry $entry, ?string $variant = null): ?string
    {
        $choice = $this->shareImages->choose($request, $entry);
        if ($choice->generated !== null) {
            return $this->urls->absolute($this->urls->share($choice->generated));
        }
        if ($choice->photo === null) {
            return null;
        }

        return $this->urls->absolute($variant === null ? $this->urls->original($choice->photo) : $this->urls->variant($choice->photo, $variant));
    }

    /**
     * The URL of $variant of the image medium $id (the original when null), or null when it is missing or not an image.
     * The medium is memoised per request when there is one, so several tags cost one query together.
     */
    public function byId(?ServerRequestInterface $request, int $id, ?string $variant = null): ?string
    {
        $find = fn(): ?Media => $this->media->find($id);
        $media = $request === null ? $find() : RequestMemo::through($request, 'media:' . $id, $find);
        if (!$media instanceof Media || !$media->isImage()) {
            return null;
        }

        return $this->urls->absolute($variant === null ? $this->urls->original($media) : $this->urls->variant($media, $variant));
    }
}
