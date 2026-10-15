<?php

declare(strict_types=1);

namespace AtarCms\View;

/**
 * The classes the admin editor (CKEditor 5 48.5.2) writes for its own features, so the stored-text filter
 * (RichTextTheme), the editor settings (EditorConfig) and the tests share one list. The defaults are CKEditor's own
 * values, used while the theme does not declare its own font sizes, markers or fonts (EditorAssetsTest checks that
 * the vendored build still contains them).
 */
final class EditorClasses
{
    /** The containers of images, videos and tables: <figure class="image|media|table">. */
    public const array FIGURES = ['image', 'media', 'table'];

    /** Text alignment of paragraphs and headings (left, the default, is never written). */
    public const array ALIGNMENT = ['text-align-left', 'text-align-center', 'text-align-right', 'text-align-justify'];

    /** Block indentation of paragraphs, headings and lists, four levels. */
    public const array INDENT = ['indent-1', 'indent-2', 'indent-3', 'indent-4'];

    /** Image placement and resizing. */
    public const array IMAGE = ['image-style-align-left', 'image-style-align-right', 'image-style-full', 'image_resized'];

    /** To-do lists. */
    public const array TODO = ['todo-list', 'todo-list__label', 'todo-list__label_without-description', 'todo-list__label__description'];

    /** Table alignment and resized columns. */
    public const array TABLE = [
        'table-style-align-left', 'table-style-align-center', 'table-style-align-right', 'table-style-block-align-left',
        'table-style-block-align-right', 'ck-table-resized',
    ];

    /** The languages of code blocks (<code class="language-{name}">) => their label (EditorConfig translates "Plain text", admin.editor.plain_text). */
    public const array CODE_LANGUAGES = [
        'plaintext' => 'Plain text',
        'html' => 'HTML',
        'css' => 'CSS',
        'javascript' => 'JavaScript',
        'php' => 'PHP',
        'sql' => 'SQL',
        'bash' => 'Bash',
        'json' => 'JSON',
        'xml' => 'XML',
    ];

    /** CKEditor's font size classes (tiny, small, big, huge). */
    public const array DEFAULT_FONT_SIZES = ['text-tiny', 'text-small', 'text-big', 'text-huge'];

    /** CKEditor's highlighter classes (four markers, two pens). */
    public const array DEFAULT_MARKERS = ['marker-yellow', 'marker-green', 'marker-pink', 'marker-blue', 'pen-red', 'pen-green'];

    /** CKEditor's eight font stacks, as it writes them (CssValue::fontFamily()). */
    public const array DEFAULT_FONTS = [
        'Arial, Helvetica, sans-serif',
        "'Courier New', Courier, monospace",
        'Georgia, serif',
        "'Lucida Sans Unicode', 'Lucida Grande', sans-serif",
        'Tahoma, Geneva, sans-serif',
        "'Times New Roman', Times, serif",
        "'Trebuchet MS', Helvetica, sans-serif",
        'Verdana, Geneva, sans-serif',
    ];

    /**
     * @return list<string> the classes of the code block languages
     */
    public static function codeLanguageClasses(): array
    {
        return array_map(static fn(string $language): string => 'language-' . $language, array_keys(self::CODE_LANGUAGES));
    }
}
