<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

final readonly class YouTubeVideo
{
    public const string WATCH_BASE = 'https://www.youtube.com/watch?v=';

    /** The cover every video has (480×360, letterboxed), with "{id}" for the video id. */
    public const string THUMBNAIL = 'https://i.ytimg.com/vi/{id}/hqdefault.jpg';

    /** The img-src source of the covers: only a response that shows them allows it (CspSources). */
    public const string THUMBNAIL_HOST = 'https://i.ytimg.com';

    public function __construct(public string $id) {}

    /**
     * The video a video id or a YouTube link of a shape VideoUrl accepts names (YouTubeType stores its id), or null.
     */
    public static function parse(string $input): ?self
    {
        $input = trim($input);
        if (preg_match(VideoUrl::YOUTUBE_ID, $input) === 1) {
            return new self($input);
        }
        $video = VideoUrl::parse($input);

        return $video !== null && $video->provider === VideoUrl::YOUTUBE ? new self($video->id) : null;
    }

    /**
     * The privacy-enhanced embed URL (VideoUrl::YOUTUBE_EMBED); the core always allows its host in frame-src.
     */
    public function embedUrl(): string
    {
        return VideoUrl::YOUTUBE_EMBED . $this->id;
    }

    public function watchUrl(): string
    {
        return self::WATCH_BASE . $this->id;
    }

    public function thumbnailUrl(): string
    {
        return str_replace('{id}', $this->id, self::THUMBNAIL);
    }
}
