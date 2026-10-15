<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * A YouTube or Vimeo video link, the only videos rich text embeds. parse() accepts these shapes (scheme http or
 * https, or none, which means https; host case-insensitive, any other query and fragment ignored):
 * - YouTube: youtube.com, www.youtube.com, m.youtube.com, youtube-nocookie.com and www.youtube-nocookie.com with
 *   /watch?v=ID (v anywhere in the query), /embed/ID, /shorts/ID or /live/ID; youtu.be/ID. ID: 11 of [A-Za-z0-9_-].
 * - Vimeo: vimeo.com and www.vimeo.com with /ID or /channels/NAME/ID; player.vimeo.com/video/ID. ID: 1-12 digits.
 * A host that is none of these is tried once more without a leading "www." (so www.youtu.be, www.m.youtube.com and
 * www.www.youtube.com pass, www.www.youtu.be does not), as CKEditor's media embed retries a link.
 * Start times and unlisted Vimeo links (vimeo.com/ID/HASH) are not supported. The admin editor's media providers
 * (editor.js) accept the same shapes: keep both in step, or a link the editor shows vanishes on save.
 */
final readonly class VideoUrl
{
    public const string YOUTUBE = 'youtube';

    public const string VIMEO = 'vimeo';

    /** The embed bases: public pages and the admin previews use the same players. */
    public const string YOUTUBE_EMBED = 'https://www.youtube-nocookie.com/embed/';

    public const string VIMEO_EMBED = 'https://player.vimeo.com/video/';

    /** The frame-src sources of the embed bases; the core always allows them. */
    public const array FRAME_SOURCES = ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'];

    public const string YOUTUBE_ID = '/^[A-Za-z0-9_-]{11}$/D';

    private const array YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    private const array VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com'];

    private const array HOSTS = [...self::YOUTUBE_HOSTS, ...self::VIMEO_HOSTS, 'youtu.be', 'player.vimeo.com'];

    private function __construct(
        public string $provider,
        public string $id,
    ) {}

    public static function parse(string $url): ?self
    {
        $url = trim($url);
        // The editor stores a link exactly as typed, so a missing scheme means https; protocol-relative links
        // (//host/...) become https:////host and are refused; any other scheme is refused below.
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $url) !== 1) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (!in_array($host, self::HOSTS, true) && str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if ($host === 'youtu.be') {
            return self::youtube(trim($path, '/'));
        }
        if (in_array($host, self::YOUTUBE_HOSTS, true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = $query['v'] ?? null;

                return is_string($id) ? self::youtube($id) : null;
            }

            return preg_match('~^/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})$~D', $path, $matches) === 1 ? self::youtube($matches[1]) : null;
        }
        if (in_array($host, self::VIMEO_HOSTS, true)) {
            return preg_match('~^/(?:channels/[A-Za-z0-9_-]+/)?([0-9]{1,12})$~D', $path, $matches) === 1 ? new self(self::VIMEO, $matches[1]) : null;
        }
        if ($host === 'player.vimeo.com') {
            return preg_match('~^/video/([0-9]{1,12})$~D', $path, $matches) === 1 ? new self(self::VIMEO, $matches[1]) : null;
        }

        return null;
    }

    /**
     * The form rich text stores: https://www.youtube.com/watch?v=ID or https://vimeo.com/ID.
     */
    public function canonical(): string
    {
        return $this->provider === self::YOUTUBE ? 'https://www.youtube.com/watch?v=' . $this->id : 'https://vimeo.com/' . $this->id;
    }

    public function embedUrl(): string
    {
        return ($this->provider === self::YOUTUBE ? self::YOUTUBE_EMBED : self::VIMEO_EMBED) . $this->id;
    }

    private static function youtube(string $id): ?self
    {
        return preg_match(self::YOUTUBE_ID, $id) === 1 ? new self(self::YOUTUBE, $id) : null;
    }
}
