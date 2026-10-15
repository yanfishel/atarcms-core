<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * Input cleaning shared by the text types.
 */
final class Text
{
    /**
     * Returns trimmed UTF-8 text with "\n" line breaks. Single-line text turns runs of control characters
     * (including line breaks) into one space; multi-line text keeps "\n" and "\t" and drops other control characters.
     *
     * @throws FieldError
     */
    public static function clean(mixed $input, bool $multiline): string
    {
        if ($input === null) {
            return '';
        }
        if (is_int($input) || is_float($input)) {
            $input = (string) $input;
        }
        if (!is_string($input) || !mb_check_encoding($input, 'UTF-8')) {
            throw new FieldError('validation.field.invalid');
        }
        $input = str_replace(["\r\n", "\r"], "\n", $input);
        $input = $multiline
            ? (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $input)
            : (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $input);

        return trim($input);
    }

    /**
     * @throws FieldError
     */
    public static function assertLength(string $text, int $max): void
    {
        if (mb_strlen($text) > $max) {
            throw new FieldError('validation.field.too_long', ['max' => $max]);
        }
    }
}
