<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Field\RichTextSanitizer;
use AtarCms\Content\RichTextTheme;
use AtarCms\View\ThemeConfig;
use PHPUnit\Framework\TestCase;

/**
 * The skeleton theme's "editor" section: a working example of styles, ready-made blocks and the editor stylesheet.
 */
final class SkeletonEditorThemeTest extends TestCase
{
    private const string THEME = __DIR__ . '/../../../skeleton/themes/default';

    public function testTheSkeletonThemeDeclaresStylesBlocksAndAnEditorStylesheet(): void
    {
        $editor = ThemeConfig::load(self::THEME)->editor;

        self::assertSame(['css/editor.css'], $editor->css);
        self::assertSame(['lead', 'striped'], array_keys($editor->styles));
        self::assertSame(['note', 'columns'], array_keys($editor->templates));
        self::assertSame(['#1e5e55', '#c0392b', '#27ae60', '#e67e22', '#7f8c8d', '#2c3e50'], array_column($editor->colors ?? [], 'color'));
        self::assertSame(['system-ui, sans-serif', "Georgia, 'Times New Roman', serif", 'ui-monospace, Consolas, monospace'], array_column($editor->fonts ?? [], 'family'));
        self::assertNull($editor->fontSizes, "the editor's own sizes");
        self::assertNull($editor->markers, "the editor's own markers");
        $labels = [
            ...array_column($editor->styles, 'label'), ...array_column($editor->templates, 'label'),
            ...array_column($editor->colors ?? [], 'label'), ...array_column($editor->fonts ?? [], 'label'),
        ];
        foreach (['en', 'ru', 'he', 'de', 'fr'] as $locale) {
            $catalog = require self::THEME . '/lang/' . $locale . '.php';
            self::assertIsArray($catalog);
            foreach ($labels as $key) {
                $value = $catalog;
                foreach (explode('.', $key) as $segment) {
                    $value = is_array($value) ? ($value[$segment] ?? null) : null;
                }
                self::assertIsString($value, $locale . ': ' . $key);
            }
        }
    }

    public function testItsReadyMadeBlocksSurviveTheSanitizerUnchanged(): void
    {
        $theme = new RichTextTheme(ThemeConfig::load(self::THEME)->editor, self::THEME);

        self::assertSame(['lead', 'table-striped', 'note', 'note-title', 'columns', 'column'], $theme->themeClasses());
        foreach ($theme->templates() as $template) {
            self::assertSame($template['html'], new RichTextSanitizer($theme->classes(), $theme->fontFamilies())->sanitize($template['html']), $template['label']);
        }
    }

    public function testItsEditorStylesheetIsScopedAndTheSiteSizesVideos(): void
    {
        $editor = (string) file_get_contents(self::THEME . '/assets/css/editor.css');
        preg_match_all('/^([^\s\/*][^{]*)\{/m', $editor, $rules);

        self::assertNotEmpty($rules[1]);
        foreach ($rules[1] as $selectors) {
            foreach (explode(',', $selectors) as $selector) {
                self::assertStringStartsWith('.ck-content ', trim($selector), 'the admin editor lives in the admin page');
            }
        }
        self::assertStringContainsString(
            '.rich-text-video { width: 100%; height: auto; aspect-ratio: 16 / 9; border: 0; display: block; }',
            (string) file_get_contents(self::THEME . '/assets/css/site.css'),
        );
    }

    /**
     * The generic figure rules (`figure.image`, `figure.table`) set margin and clear, so each alignment rule must
     * outrank them: a compound selector (`.image.image-style-align-left`) as the vendor stylesheet writes them.
     */
    public function testItsAlignmentRulesOutrankTheFigureRules(): void
    {
        foreach (['editor.css', 'site.css'] as $file) {
            $css = (string) file_get_contents(self::THEME . '/assets/css/' . $file);
            preg_match_all('/^([^\s\/*][^{]*)\{/m', $css, $rules);
            $checked = 0;
            foreach ($rules[1] as $selectors) {
                foreach (explode(',', $selectors) as $selector) {
                    if (preg_match('/\.(?:image|table)-style-(?:block-)?align-/', $selector) !== 1) {
                        continue;
                    }
                    ++$checked;
                    self::assertMatchesRegularExpression(
                        '/\.(?:image|table)\.(?:image|table)-style-(?:block-)?align-|img\.image-style-align-/',
                        $selector,
                        $file . ': ' . trim($selector),
                    );
                }
            }
            self::assertGreaterThanOrEqual(7, $checked, $file);
            foreach (['left', 'right'] as $side) {
                self::assertMatchesRegularExpression('/^[^{]*\bimg\.image-style-align-' . $side . '\b[^{]*\{[^}]*\bfloat: ' . $side . '\b/m', $css, $file . ': inline ' . $side);
            }
        }
    }

    /**
     * Every class stored rich text may keep has a rule in the editor stylesheet and in the site stylesheet, so the
     * editor shows the text as the site does (the code block language classes only name the language).
     */
    public function testItsStylesheetsStyleEveryClassTheEditorWrites(): void
    {
        $theme = new RichTextTheme(ThemeConfig::load(self::THEME)->editor, self::THEME);

        foreach (['editor.css' => '.ck-content', 'site.css' => '.rich-text'] as $file => $scope) {
            $css = (string) file_get_contents(self::THEME . '/assets/css/' . $file);
            foreach ($theme->classes() as $class) {
                if (!str_starts_with($class, 'language-')) {
                    self::assertMatchesRegularExpression('/^' . preg_quote($scope, '/') . ' [^{]*\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/m', $css, $file . ': .' . $class);
                }
            }
        }
    }
}
