<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * A YouTube video: accepts a video id or a YouTube link of a shape VideoUrl accepts, stores the id.
 */
final class YouTubeType implements FieldType
{
    public function name(): string
    {
        return 'youtube';
    }

    public function normalize(mixed $input, Field $field): ?string
    {
        if (is_string($input)) {
            $input = trim($input);
        }
        if ($input === null || $input === '') {
            return null;
        }
        $video = is_string($input) ? YouTubeVideo::parse($input) : null;
        if ($video === null) {
            throw new FieldError('validation.field.youtube');
        }

        return $video->id;
    }

    public function read(mixed $stored, Field $field): ?YouTubeVideo
    {
        return is_string($stored) && preg_match(VideoUrl::YOUTUBE_ID, $stored) === 1 ? new YouTubeVideo($stored) : null;
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }
}
