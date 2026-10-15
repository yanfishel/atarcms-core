<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryMedia;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Container;
use AtarCms\View\Renderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Theme helpers for media:
 * - mediaFor($page->entries) (or one entry): the media of the entries' image, gallery and file fields and SEO images,
 *   in one query; ->get($entry->field('cover')) and ->list($entry->field('images')) look them up;
 * - img($media, 'medium', ['alt' => …, 'class' => …, 'sizes' => …]): the image markup (see ImageTag), '' for null;
 *   'sources' => ['(max-width: 40rem)' => 'hero_phone' or [$otherMedia, 'hero_phone']] adds art-directed sources;
 * - mediaUrl($media) (the original, e.g. a PDF: link it with download="<?= $this->e($file->originalName) ?>") or
 *   mediaUrl($media, 'large') (a variant in the original format);
 * - ogImage($entry, 'large'): the absolute URL of the entry's sharing image (that variant when given), or null.
 */
final class MediaViewHelpers
{
    public static function register(Renderer $renderer, Container $container): void
    {
        $renderer->helper(
            'mediaFor',
            /**
             * @param Entry|list<Entry> $entries
             */
            static function (Entry|array $entries) use ($container): MediaSet {
                $list = [];
                foreach ($entries instanceof Entry ? [$entries] : $entries as $entry) {
                    /** @var Entry $entry */
                    $list[] = $entry;
                }

                return new MediaSet($container->service(MediaRepository::class)->byIds($container->service(EntryMedia::class)->ids($list)));
            },
        );
        $renderer->requestHelper(
            'img',
            static function (ServerRequestInterface $request, ?Media $media, string $variant, array $attributes = []) use ($container): string {
                if ($media === null) {
                    return '';
                }
                if (($attributes['alt'] ?? null) === null) {
                    $attributes['alt'] = $media->alt(RequestLocale::of($request)->code)
                        ?? $media->alt($container->service(Locales::class)->default())
                        ?? '';
                }

                return $container->service(ImageTag::class)->render($media, $variant, $attributes);
            },
        );
        $renderer->helper(
            'mediaUrl',
            static function (Media $media, ?string $variant = null) use ($container): string {
                $urls = $container->service(MediaUrls::class);

                return $variant === null ? $urls->original($media) : $urls->variant($media, $variant);
            },
        );
        $renderer->optionalRequestHelper(
            'ogImage',
            static fn(?ServerRequestInterface $request, Entry $entry, ?string $variant = null): ?string => $container
                ->service(OgImage::class)
                ->url($request, $entry, $variant),
        );
    }
}
