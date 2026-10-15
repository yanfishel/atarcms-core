<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * HTML from the admin editor, sanitized on save with the allow-list of RichTextSanitizer, or its minimal profile for
 * a Field::minimal() field (any class of valid syntax stays here; EntryService then keeps only the theme's classes
 * through RichTextTheme). The posted HTML may be up to DEFAULT_MAX characters; ->max() limits the visible text
 * (RichText::text()). Videos are stored as <oembed url> and read as players (VideoEmbeds).
 */
final class RichTextType implements FieldType
{
    public const int DEFAULT_MAX = 200000;

    private ?RichTextSanitizer $sanitizer = null;

    private ?RichTextSanitizer $minimalSanitizer = null;

    public function name(): string
    {
        return 'richText';
    }

    public function normalize(mixed $input, Field $field): ?string
    {
        if ($input === null) {
            return null;
        }
        if (!is_string($input) || !mb_check_encoding($input, 'UTF-8')) {
            throw new FieldError('validation.field.invalid');
        }
        Text::assertLength($input, self::DEFAULT_MAX);
        $html = $field->minimal
            ? ($this->minimalSanitizer ??= new RichTextSanitizer(null, null, minimal: true))->sanitize($input)
            : ($this->sanitizer ??= new RichTextSanitizer(null, null))->sanitize($input);
        if (self::isBlank($html)) {
            return null;
        }
        if ($field->max !== null) {
            Text::assertLength(new RichText($html)->text(), (int) $field->max);
        }

        return $html;
    }

    /**
     * The stored HTML with its videos as players; the admin form and EntryMedia read the stored value instead.
     */
    public function read(mixed $stored, Field $field): RichText
    {
        return new RichText(is_string($stored) ? VideoEmbeds::render($stored) : '');
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }

    /**
     * Whether sanitized HTML shows nothing: no text, image, rule or video.
     */
    public static function isBlank(string $html): bool
    {
        if (str_contains($html, '<img') || str_contains($html, '<hr') || str_contains($html, '<oembed url=')) {
            return false;
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\s\x{A0}]+/u', '', $text) === '';
    }
}
