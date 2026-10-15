<?php

declare(strict_types=1);

namespace AtarCms\Media;

use InvalidArgumentException;

/**
 * The markup of the img() theme helper: a <picture> with a WebP <source> and an <img> in the original format (a
 * plain <img> for WebP originals). srcset lists the requested variant and the theme variants of its family with
 * their real widths (never upscaled, duplicates dropped); width/height are the requested variant's size, computed
 * from the stored dimensions without reading the file. Every value is escaped.
 *
 * Art direction: the "sources" option maps media queries (in order) to another variant of the same picture or to a
 * [Media, variant] pair; each adds <source media="…"> elements (WebP and the original format, each with its own
 * srcset, sizes, width and height) before the default ones. A pair whose Media is null (an empty field) adds nothing.
 * With AVIF output (ImageFormats) every group of sources starts with an AVIF one.
 */
final class ImageTag
{
    public const array ATTRIBUTES = ['alt', 'class', 'id', 'title', 'sizes', 'loading', 'decoding', 'fetchpriority'];

    public function __construct(
        private readonly ImageVariants $variants,
        private readonly MediaUrls $urls,
        private readonly ImageFormats $formats = new ImageFormats(),
    ) {}

    /**
     * @param array<mixed> $attributes some of ATTRIBUTES; "alt" defaults to "", "sizes" to the variant's width,
     *                                 "loading" to "lazy", "decoding" to "async"; and "sources", the art-directed
     *                                 sources: media query => variant name or [?Media, variant name]
     */
    public function render(Media $media, string $variant, array $attributes = []): string
    {
        $spec = $this->spec($variant);
        self::assertImage($media);
        // A null value (for example an empty field passed as the alt text) means "not set".
        $attributes = array_filter($attributes, static fn(mixed $value): bool => $value !== null);
        $sources = $this->sources($media, $attributes['sources'] ?? []);
        unset($attributes['sources']);
        foreach ($attributes as $name => $value) {
            if (!in_array($name, self::ATTRIBUTES, true)) {
                throw new InvalidArgumentException(sprintf('img() does not accept the attribute "%s"; use %s, sources', $name, implode(', ', self::ATTRIBUTES)));
            }
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException(sprintf('The img() attribute "%s" must be a string', $name));
            }
        }
        $given = isset($attributes['sizes']) ? (string) $attributes['sizes'] : null;
        [$width, $height] = $spec->dimensions((int) $media->width, (int) $media->height);
        $sizes = $given ?? self::defaultSizes($width);

        $html = '';
        foreach ($sources as $query => [$sourceMedia, $sourceSpec]) {
            [$sourceWidth, $sourceHeight] = $sourceSpec->dimensions((int) $sourceMedia->width, (int) $sourceMedia->height);
            $html .= $this->sourceTags($sourceMedia, $sourceSpec, [
                'media' => $query,
                'sizes' => $given ?? self::defaultSizes($sourceWidth),
                'width' => $sourceWidth,
                'height' => $sourceHeight,
            ], true);
        }
        $html .= $this->sourceTags($media, $spec, ['sizes' => $sizes], false);

        $img = self::tag('img', [
            'src' => $this->urls->variant($media, $spec->name),
            'srcset' => $this->srcset($media, $spec, null),
            'sizes' => $sizes,
            'width' => $width,
            'height' => $height,
            'alt' => $attributes['alt'] ?? '',
            'class' => $attributes['class'] ?? null,
            'id' => $attributes['id'] ?? null,
            'title' => $attributes['title'] ?? null,
            'loading' => $attributes['loading'] ?? 'lazy',
            'decoding' => $attributes['decoding'] ?? 'async',
            'fetchpriority' => $attributes['fetchpriority'] ?? null,
        ]);

        return $html === '' ? $img : '<picture>' . $html . $img . '</picture>';
    }

    /**
     * The <source> elements of one variant: AVIF (when on), WebP (unless the original is WebP) and, for an
     * art-directed source, the original format (a default source has the <img> for it).
     *
     * @param array{media?: string, sizes: string, width?: int, height?: int} $attributes
     */
    private function sourceTags(Media $media, ImageVariant $spec, array $attributes, bool $original): string
    {
        $query = $attributes['media'] ?? null;
        $rest = $attributes;
        unset($rest['media']);
        $html = '';
        if ($this->formats->avif() && !ImageVariants::isIcon($spec->name)) {
            $html .= self::tag('source', ['media' => $query, 'type' => 'image/avif', 'srcset' => $this->srcset($media, $spec, 'image/avif'), ...$rest]);
        }
        if ($media->mime !== 'image/webp') {
            $html .= self::tag('source', ['media' => $query, 'type' => 'image/webp', 'srcset' => $this->srcset($media, $spec, 'image/webp'), ...$rest]);
        }
        if ($original) {
            $html .= self::tag('source', ['media' => $query, 'srcset' => $this->srcset($media, $spec, null), ...$rest]);
        }

        return $html;
    }

    /**
     * The variant and the theme variants of its family, by their real width for $media, as a srcset in $format
     * (null: the original's).
     */
    private function srcset(Media $media, ImageVariant $spec, ?string $format): string
    {
        $candidates = [$spec->dimensions((int) $media->width, (int) $media->height)[0] => $spec];
        foreach ($this->variants->family($spec) as $member) {
            $candidates[$member->dimensions((int) $media->width, (int) $media->height)[0]] ??= $member;
        }
        ksort($candidates);
        $parts = [];
        foreach ($candidates as $candidateWidth => $candidate) {
            $url = $format === 'image/avif'
                ? $this->urls->avif($media, $candidate->name)
                : $this->urls->variant($media, $candidate->name, $format === 'image/webp');
            $parts[] = $url . ' ' . $candidateWidth . 'w';
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<string, array{Media, ImageVariant}> media query => the picture and variant for it
     */
    private function sources(Media $media, mixed $sources): array
    {
        if (!is_array($sources)) {
            throw new InvalidArgumentException('The img() option "sources" must map media queries to a variant name or [Media, variant name]');
        }
        $parsed = [];
        foreach ($sources as $query => $source) {
            if (!is_string($query) || trim($query) === '') {
                throw new InvalidArgumentException('The img() option "sources" must be keyed by media queries such as "(max-width: 40rem)"');
            }
            if (is_string($source)) {
                $parsed[$query] = [$media, $this->spec($source)];

                continue;
            }
            if (!is_array($source) || !array_is_list($source) || count($source) !== 2 || !($source[0] === null || $source[0] instanceof Media) || !is_string($source[1])) {
                throw new InvalidArgumentException(sprintf('The img() source for "%s" must be a variant name or [Media, variant name]', $query));
            }
            $spec = $this->spec($source[1]);
            if ($source[0] !== null) {
                self::assertImage($source[0]);
                $parsed[$query] = [$source[0], $spec];
            }
        }

        return $parsed;
    }

    private function spec(string $variant): ImageVariant
    {
        return $this->variants->get($variant)
            ?? throw new InvalidArgumentException(sprintf('Unknown image variant "%s"; declare it under "images" in theme.php', $variant));
    }

    private static function assertImage(Media $media): void
    {
        if (!$media->isImage() || $media->width === null || $media->height === null) {
            throw new InvalidArgumentException(sprintf('Media #%d is not an image', $media->id));
        }
    }

    private static function defaultSizes(int $width): string
    {
        return sprintf('(max-width: %1$dpx) 100vw, %1$dpx', $width);
    }

    /**
     * @param array<string, mixed> $attributes null values are left out
     */
    private static function tag(string $name, array $attributes): string
    {
        $html = '<' . $name;
        foreach ($attributes as $attribute => $value) {
            if (is_string($value) || is_int($value)) {
                $html .= ' ' . $attribute . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
            }
        }

        return $html . '>';
    }
}
