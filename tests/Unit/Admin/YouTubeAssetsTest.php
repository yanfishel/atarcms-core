<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * youtube.js follows the admin script rules (no markup, styles, setAttribute, requests or network URLs): the YouTube
 * field's "Check video" clones the server's player template and gives it the address of the server's embed base and
 * the id read from the field; an unreadable value clones the server's error toast.
 */
final class YouTubeAssetsTest extends TestCase
{
    private const string ASSETS = __DIR__ . '/../../../resources/admin/assets';

    public function testTheModuleNeverInjectsMarkupStylesOrRequests(): void
    {
        $module = (string) file_get_contents(self::ASSETS . '/youtube.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'htmx'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $module, $forbidden);
        }
        self::assertStringContainsString('widget.hidden = id === null;', $module, 'the card follows the input');
        self::assertStringContainsString("let shown = widget.dataset.youtubeId ?? '';", $module, 'the video the card shows, from the server');
        self::assertStringContainsString("image.src = widget.dataset.youtubeThumb.replace('{id}', id);", $module, 'the cover address comes from the server');
        self::assertStringContainsString('open.href = widget.dataset.youtubeWatch + id;', $module);
        self::assertStringContainsString("frame.src = widget.dataset.youtubeEmbed + id + '?autoplay=1';", $module, 'Play: the server\'s embed base and the id, started at once');
        self::assertStringContainsString('setTimeout(update, 300)', $module, 'typing updates the card after a pause');
        self::assertStringContainsString("input.dispatchEvent(new Event('input', { bubbles: true }));", $module, 'Remove is a change autosave hears');
        self::assertStringContainsString("input.dispatchEvent(new Event('change', { bubbles: true }));", $module);
        self::assertStringContainsString("input.addEventListener('change', ", $module, 'an unreadable value is reported when the input is left');
        self::assertStringContainsString("querySelector('template[data-youtube-player]')", $module);
        self::assertStringContainsString("querySelector('template[data-youtube-invalid]')", $module);
        self::assertStringContainsString("document.querySelector('[data-toasts]')", $module, 'the error toast goes to the stack');
        self::assertStringContainsString('/^[A-Za-z0-9_-]{11}$/', $module, 'a video id as VideoUrl::YOUTUBE_ID');
        self::assertStringContainsString('toast?.remove();', $module, 'one error toast per widget, not a stack of them');
    }

    /**
     * The link is split as PHP's parse_url() splits it, not by the URL parser, which repairs what the server refuses:
     * a protocol-relative "//host", "https:host" without slashes, backslashes and dot segments in the path.
     */
    public function testTheLinkIsReadLikeTheServerReadsIt(): void
    {
        $module = (string) file_get_contents(self::ASSETS . '/youtube.js');

        self::assertStringNotContainsString('new URL(', $module, 'the URL parser normalises what parse_url() keeps as written');
        self::assertStringContainsString("const url = /^[A-Za-z][A-Za-z0-9+.-]*:/.test(text) ? text : '//' + text;", $module, 'no scheme: a host and path');
        self::assertStringContainsString('/^(?:([A-Za-z][A-Za-z0-9+.-]*):)?\/\/([^\/?#]*)([^?#]*)(?:\?([^#]*))?/.exec(url)', $module);
        self::assertStringContainsString("const [, scheme = 'https', authority, path, query = ''] = parts;", $module, 'no scheme means https, as on the server');
        self::assertStringContainsString("(key === 'v' || key.startsWith('v['))", $module, 'the last v wins, as parse_str() reads it; an array v names no video');
    }
}
