<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Content\Field\RichTextSanitizer;
use AtarCms\Content\Field\RichTextType;
use AtarCms\View\EditorClasses;
use AtarCms\View\EditorSettings;
use Dom\HTMLDocument;

/**
 * The rich-text side of the active theme's "editor" settings: the classes stored rich text may keep (the theme's own
 * from its styles and template files, and those of the editor's features: EditorClasses, the font sizes and markers
 * in effect), the font families it may keep, the ready-made blocks, and the filter EntryService applies after
 * RichTextType::normalize(). The template files are read once per instance, when first needed.
 */
final class RichTextTheme
{
    /** @var list<array{label: string, html: string}>|null */
    private ?array $templates = null;

    /** @var list<string>|null */
    private ?array $themeClasses = null;

    /** @var list<string>|null */
    private ?array $classes = null;

    private ?RichTextSanitizer $sanitizer = null;

    private ?RichTextSanitizer $minimalSanitizer = null;

    public function __construct(
        private readonly EditorSettings $settings,
        private readonly string $themeDirectory,
    ) {}

    /**
     * @return list<array{label: string, html: string}> the ready-made blocks in theme order; labels untranslated
     */
    public function templates(): array
    {
        if ($this->templates === null) {
            $this->templates = [];
            foreach ($this->settings->templates as $template) {
                $this->templates[] = [
                    'label' => $template['label'],
                    'html' => trim((string) file_get_contents(rtrim($this->themeDirectory, '/') . '/' . $template['file'])),
                ];
            }
        }

        return $this->templates;
    }

    /**
     * @return list<string> the theme's own classes: those of its styles and of its template files, each once (the
     *                      editor's General HTML Support lets these through; its features model their own classes)
     */
    public function themeClasses(): array
    {
        if ($this->themeClasses === null) {
            $classes = [];
            foreach ($this->settings->styles as $style) {
                $classes = [...$classes, ...$style['classes']];
            }
            foreach ($this->templates() as $template) {
                $classes = [...$classes, ...self::classesOf($template['html'])];
            }
            $this->themeClasses = array_values(array_unique($classes));
        }

        return $this->themeClasses;
    }

    /**
     * @return list<string> every class stored rich text may keep: themeClasses() and the classes of the editor's
     *                      features, the font sizes and markers of the theme (or the editor's defaults), each once
     */
    public function classes(): array
    {
        if ($this->classes === null) {
            $fontSizes = $this->fontSizeClasses();
            $markers = $this->settings->markers === null ? EditorClasses::DEFAULT_MARKERS : array_column($this->settings->markers, 'class');
            $this->classes = array_values(array_unique([
                ...$this->themeClasses(),
                ...EditorClasses::FIGURES,
                ...EditorClasses::ALIGNMENT,
                ...EditorClasses::INDENT,
                ...EditorClasses::IMAGE,
                ...EditorClasses::TODO,
                ...EditorClasses::TABLE,
                ...EditorClasses::codeLanguageClasses(),
                ...$fontSizes,
                ...$markers,
            ]));
        }

        return $this->classes;
    }

    /**
     * @return list<string> the font size classes in effect: the theme's, the editor's defaults, or none
     */
    public function fontSizeClasses(): array
    {
        return $this->settings->fontSizes === null ? EditorClasses::DEFAULT_FONT_SIZES : array_column($this->settings->fontSizes, 'class');
    }

    /**
     * @return list<string> the font families stored rich text may keep: the theme's fonts, or the editor's defaults
     */
    public function fontFamilies(): array
    {
        return $this->settings->fonts === null ? EditorClasses::DEFAULT_FONTS : array_column($this->settings->fonts, 'family');
    }

    /**
     * Sanitized rich text with only classes() and fontFamilies() left (colours are never filtered: the editor's
     * picker allows any), or for a minimal field the minimal profile with only fontSizeClasses(); null when nothing
     * is left to show.
     */
    public function filter(string $html, bool $minimal = false): ?string
    {
        $html = $minimal
            ? ($this->minimalSanitizer ??= new RichTextSanitizer($this->fontSizeClasses(), null, minimal: true))->sanitize($html)
            : ($this->sanitizer ??= new RichTextSanitizer($this->classes(), $this->fontFamilies()))->sanitize($html);

        return RichTextType::isBlank($html) ? null : $html;
    }

    /**
     * @return list<string> the class tokens of valid syntax in $html
     */
    private static function classesOf(string $html): array
    {
        if ($html === '') {
            return [];
        }
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        $classes = [];
        foreach ($document->querySelectorAll('[class]') as $element) {
            foreach ($element->classList as $class) {
                if (is_string($class) && preg_match(EditorSettings::CLASS_PATTERN, $class) === 1) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }
}
