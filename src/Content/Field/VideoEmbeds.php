<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * Turns the videos of stored rich text (<oembed url="…"></oembed>, written by the admin editor and canonicalized by
 * the sanitizer) into players for visitors. The iframes carry a class instead of inline styles (CSP): themes size
 * them with .rich-text-video. The title is the provider's name, which needs no translation.
 */
final class VideoEmbeds
{
    /**
     * $html with every <oembed> tag replaced by its player (removed without a supported URL) and every </oembed> removed;
     * children of an <oembed> stay as content.
     */
    public static function render(string $html): string
    {
        if (!str_contains($html, '<oembed')) {
            return $html;
        }

        // Each tag is replaced on its own, so the tag pairs around (and inside) an <oembed> stay balanced.
        return preg_replace_callback('~<oembed\b([^>]*)>|</oembed\s*>~i', static function (array $matches): string {
            if (!isset($matches[1])) {
                return '';
            }
            if (preg_match('~(?:^|\s)url="([^"]*)"~i', $matches[1], $url) !== 1) {
                return '';
            }
            $video = VideoUrl::parse(html_entity_decode($url[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $video === null ? '' : self::iframe($video);
        }, $html) ?? $html;
    }

    private static function iframe(VideoUrl $video): string
    {
        $youtube = $video->provider === VideoUrl::YOUTUBE;

        return sprintf(
            '<iframe class="rich-text-video" src="%s" title="%s" loading="lazy" allow="%s" referrerpolicy="strict-origin-when-cross-origin"></iframe>',
            htmlspecialchars($video->embedUrl(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            $youtube ? 'YouTube' : 'Vimeo',
            $youtube ? 'accelerometer; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture; web-share' : 'fullscreen; picture-in-picture',
        );
    }
}
