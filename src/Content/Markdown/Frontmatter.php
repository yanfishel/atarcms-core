<?php

declare(strict_types=1);

namespace AtarCms\Content\Markdown;

/**
 * The YAML frontmatter block at the top of a markdown twin (EntryMarkdown::document()): "---", one "key: value" line
 * per field, "---". Every value is a double-quoted scalar on one line, whatever it holds: whitespace runs (line breaks
 * and separators included) collapse to one space, invalid UTF-8 becomes U+FFFD, and the quote, the backslash and every
 * non-printable character are escaped with the escapes JSON and YAML share (\", \\, \uXXXX), so a title such as
 * 'Note: "---" #1' can neither end the block nor turn into a YAML key, list, comment, number or boolean.
 */
final class Frontmatter
{
    /**
     * @param array<string, string> $fields key => value; the keys are the caller's plain words ("title", "url")
     */
    public static function render(array $fields): string
    {
        $lines = ['---'];
        foreach ($fields as $key => $value) {
            $lines[] = $key . ': ' . self::quote($value);
        }
        $lines[] = '---';

        return implode("\n", $lines) . "\n";
    }

    public static function quote(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $decoded = json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            $value = is_string($decoded) ? $decoded : '';
        }
        $line = trim((string) preg_replace('/\s+/u', ' ', $value));
        // json_encode() escapes the quote, the backslash, C0 controls and U+2028/U+2029; YAML also wants DEL, the C1
        // controls, the byte order mark and the noncharacters U+FFFE/U+FFFF escaped (outside its printable set).
        $quoted = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return (string) preg_replace_callback(
            '/[\x{7F}-\x{9F}\x{FEFF}\x{FFFE}\x{FFFF}]/u',
            static fn(array $match): string => sprintf('\u%04x', mb_ord($match[0], 'UTF-8')),
            $quoted,
        );
    }
}
