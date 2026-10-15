<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use AtarCms\Content\Menu\MenuUrl;

/**
 * Slides of a hero slider: each an image of the media library (required; a video's poster) with an optional video
 * (an MP4/WebM of the media library that plays instead of the picture), title, subtitle, link and button text, stored
 * as a list of maps ({"image": 12, "video": 30, "title": "…", "subtitle": "…", "url": "…", "button": "…"}; an empty
 * video and empty texts are left out). Field::max() limits the count (default DEFAULT_MAX). Themes read a list<Slide>.
 *
 * The form posts fields[{name}][{i}][image|video|title|subtitle|url|button|position|delete]: the indices may have gaps, a
 * numeric position orders the rows (rows without one follow in posted order), a checked delete drops a row and a row
 * left entirely empty is no slide. Links pass the menu links' allowlist (MenuUrl).
 */
final class SlidesType implements MediaFieldType
{
    public const int DEFAULT_MAX = 10;

    /** A slide's texts and their maximum lengths, in stored order. */
    public const array TEXTS = ['title' => 255, 'subtitle' => 500, 'url' => 255, 'button' => 100];

    /** A slide's media keys: the picture (required, the poster of a video) and the video (optional, video/*). */
    public const array MEDIA = ['image', 'video'];

    public function name(): string
    {
        return 'slides';
    }

    /**
     * @return list<array<string, int|string>>|null
     */
    public function normalize(mixed $input, Field $field): ?array
    {
        if ($input === null || $input === '') {
            return null;
        }
        if (!is_array($input)) {
            throw new FieldError('validation.field.invalid');
        }
        foreach ($input as $row) {
            if (!is_array($row)) {
                throw new FieldError('validation.field.invalid');
            }
        }
        $slides = [];
        foreach (self::ordered($input) as $row) {
            $number = count($slides) + 1;
            $texts = [];
            foreach (self::TEXTS as $part => $max) {
                $text = Text::clean($row[$part] ?? null, multiline: false);
                if ($text === '') {
                    continue;
                }
                if (mb_strlen($text) > $max) {
                    throw new FieldError(match ($part) {
                        'title' => 'validation.slides.title_long',
                        'subtitle' => 'validation.slides.subtitle_long',
                        'url' => 'validation.slides.url_long',
                        default => 'validation.slides.button_long',
                    }, ['slide' => $number, 'max' => $max]);
                }
                if ($part === 'url') {
                    $text = MenuUrl::normalize($text) ?? throw new FieldError('validation.slides.url', ['slide' => $number]);
                }
                $texts[$part] = $text;
            }
            $image = MediaIds::one($row['image'] ?? null);
            $video = MediaIds::one($row['video'] ?? null);
            if ($image === null) {
                if ($texts === [] && $video === null) {
                    continue;
                }

                throw new FieldError('validation.slides.image', ['slide' => $number]);
            }
            $slides[] = ['image' => $image, ...($video === null ? [] : ['video' => $video]), ...$texts];
        }
        if ($slides === []) {
            return null;
        }
        $max = (int) ($field->max ?? self::DEFAULT_MAX);
        if (count($slides) > $max) {
            throw new FieldError('validation.field.too_many', ['max' => $max]);
        }

        return $slides;
    }

    /**
     * @return list<Slide>
     */
    public function read(mixed $stored, Field $field): array
    {
        if (!is_array($stored)) {
            return [];
        }
        $slides = [];
        foreach ($stored as $item) {
            $image = is_array($item) ? ($item['image'] ?? null) : null;
            if (!is_array($item) || !is_int($image) || $image < 1) {
                continue;
            }
            $text = static fn(string $part): ?string => isset($item[$part]) && is_string($item[$part]) && $item[$part] !== '' ? $item[$part] : null;
            $video = $item['video'] ?? null;
            $slides[] = new Slide($image, $text('title'), $text('subtitle'), $text('url'), $text('button'), is_int($video) && $video > 0 ? $video : null);
        }

        return $slides;
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }

    public function mediaIds(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }
        $ids = [];
        foreach ($stored as $item) {
            foreach (self::MEDIA as $key) {
                $id = is_array($item) ? ($item[$key] ?? null) : null;
                if (is_int($id) && $id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    public function imagesOnly(): bool
    {
        return true;
    }

    /**
     * The rows of a stored or submitted value as strings, read leniently for the admin widget (ordered by position,
     * deleted and empty rows left out, anything malformed as ''); the server validates the value on save.
     *
     * @return list<array{image: string, video: string, title: string, subtitle: string, url: string, button: string}>
     */
    public static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $string = static fn(mixed $value): string => is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        $rows = [];
        foreach (self::ordered($value) as $row) {
            $item = [
                'image' => $string($row['image'] ?? null),
                'video' => $string($row['video'] ?? null),
                'title' => $string($row['title'] ?? null),
                'subtitle' => $string($row['subtitle'] ?? null),
                'url' => $string($row['url'] ?? null),
                'button' => $string($row['button'] ?? null),
            ];
            if (implode('', $item) !== '') {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * The rows that are arrays and not marked for deletion, rows with a numeric position first by that position,
     * then the others in posted order.
     *
     * @param array<mixed> $rows
     *
     * @return list<array<mixed>>
     */
    private static function ordered(array $rows): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['delete'] ?? null, [null, '', '0', 0, false], true)) {
                continue;
            }
            $position = $row['position'] ?? null;
            $position = is_int($position) || (is_string($position) && preg_match('/^\s*-?\d{1,9}\s*$/', $position) === 1) ? (int) trim((string) $position) : null;
            $kept[] = ['numbered' => $position === null ? 1 : 0, 'position' => $position ?? 0, 'index' => count($kept), 'row' => $row];
        }
        usort($kept, static fn(array $a, array $b): int => [$a['numbered'], $a['position'], $a['index']] <=> [$b['numbered'], $b['position'], $b['index']]);

        return array_column($kept, 'row');
    }
}
