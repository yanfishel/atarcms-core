<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Field\RichTextSanitizer;
use AtarCms\Content\RichTextTheme;
use AtarCms\Kernel\Application;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\View\EditorClasses;
use AtarCms\View\EditorSettings;
use PHPUnit\Framework\TestCase;

final class RichTextThemeTest extends TestCase
{
    use TempDirectory;

    public function testCollectsTheStyleClassesAndTheTemplateClasses(): void
    {
        $this->writeFile('editor/note.html', "\n<div class=\"note  wide\"><p class=\"note-title lead\">Note</p><p>Text</p></div>\n");
        $this->writeFile('editor/quote.html', '<blockquote class="pull 1bad"><p>Quote</p></blockquote>');
        $theme = new RichTextTheme(new EditorSettings(
            styles: [
                'lead' => ['label' => 'Lead', 'element' => 'p', 'classes' => ['lead']],
                'striped' => ['label' => 'Striped', 'element' => 'table', 'classes' => ['table-striped', 'image']],
            ],
            templates: [
                'note' => ['label' => 'site.editor.note', 'file' => 'editor/note.html'],
                'quote' => ['label' => 'Quote', 'file' => 'editor/quote.html'],
            ],
        ), $this->tempDir());

        self::assertSame(['lead', 'table-striped', 'image', 'note', 'wide', 'note-title', 'pull'], $theme->themeClasses());
        self::assertSame(['lead', 'table-striped', 'image', 'note', 'wide', 'note-title', 'pull', 'media', 'table'], array_slice($theme->classes(), 0, 9));
        self::assertSame([
            ['label' => 'site.editor.note', 'html' => '<div class="note  wide"><p class="note-title lead">Note</p><p>Text</p></div>'],
            ['label' => 'Quote', 'html' => '<blockquote class="pull 1bad"><p>Quote</p></blockquote>'],
        ], $theme->templates());
    }

    public function testKeepsTheClassesOfEveryEditorFeatureAndTheEditorsDefaultSizesAndMarkers(): void
    {
        $theme = new RichTextTheme(new EditorSettings(), '');

        self::assertSame([], $theme->themeClasses());
        self::assertSame([
            ...EditorClasses::FIGURES, ...EditorClasses::ALIGNMENT, ...EditorClasses::INDENT, ...EditorClasses::IMAGE,
            ...EditorClasses::TODO, ...EditorClasses::TABLE,
            'language-plaintext', 'language-html', 'language-css', 'language-javascript', 'language-php', 'language-sql',
            'language-bash', 'language-json', 'language-xml',
            'text-tiny', 'text-small', 'text-big', 'text-huge',
            'marker-yellow', 'marker-green', 'marker-pink', 'marker-blue', 'pen-red', 'pen-green',
        ], $theme->classes());
        self::assertSame(EditorClasses::DEFAULT_FONTS, $theme->fontFamilies());
        self::assertSame([], $theme->templates());
        self::assertSame(
            '<p>Pasted</p><figure class="table"><table><tbody><tr><td>x</td></tr></tbody></table></figure>',
            $theme->filter('<p class="MsoNormal">Pasted</p><figure class="table"><table class="table-striped"><tbody><tr><td>x</td></tr></tbody></table></figure>'),
        );
    }

    public function testTheThemesSizesAndMarkersReplaceTheDefaultsAndAnEmptyListRemovesThem(): void
    {
        $own = new RichTextTheme(new EditorSettings(
            fontSizes: ['small' => ['label' => 'Small', 'class' => 'size-small']],
            markers: ['note' => ['label' => 'Note', 'class' => 'mark-note', 'color' => '#ff0', 'type' => 'marker']],
        ), '');
        $off = new RichTextTheme(new EditorSettings(fontSizes: [], markers: []), '');

        self::assertContains('size-small', $own->classes());
        self::assertContains('mark-note', $own->classes());
        foreach ([...EditorClasses::DEFAULT_FONT_SIZES, ...EditorClasses::DEFAULT_MARKERS] as $default) {
            self::assertNotContains($default, $own->classes(), $default);
            self::assertNotContains($default, $off->classes(), $default);
        }
        self::assertSame('<p><span>a</span><mark>b</mark></p>', $off->filter('<p><span class="text-big">a</span><mark class="marker-yellow">b</mark></p>'));
        self::assertSame(
            '<p><span class="size-small">a</span><mark class="mark-note">b</mark></p>',
            $own->filter('<p><span class="size-small text-big">a</span><mark class="mark-note marker-yellow">b</mark></p>'),
        );
    }

    public function testFilterKeepsTheThemesClassesAndFontsAndEveryColour(): void
    {
        $theme = new RichTextTheme(new EditorSettings(
            styles: ['lead' => ['label' => 'Lead', 'element' => 'p', 'classes' => ['lead']]],
            fonts: [['label' => 'Serif', 'family' => 'Georgia, serif']],
        ), '');

        self::assertSame(['Georgia, serif'], $theme->fontFamilies());
        self::assertSame(
            '<p class="lead text-align-center">A <span style="font-family: Georgia, serif">serif</span>'
            . ' <span style="color: #123456">mono</span></p><figure class="image image-style-align-left"><img src="/uploads/a.png" alt="A" /></figure>',
            $theme->filter(
                '<p class="lead old-theme text-align-center">A <span style="font-family:Georgia,serif">serif</span>'
                . ' <span style="color:#123456;font-family:Courier, monospace">mono</span></p>'
                . '<figure class="image image-style-align-left"><img src="/uploads/a.png" alt="A" /></figure>',
            ),
        );
        self::assertNull($theme->filter('<p class="lead"> </p>'), 'nothing to show');
        self::assertSame('<p><span>a</span></p>', new RichTextTheme(new EditorSettings(fonts: []), '')->filter('<p><span style="font-family:Georgia, serif">a</span></p>'));
    }

    public function testAMinimalFieldKeepsOnlyTheFontSizesInEffect(): void
    {
        $theme = new RichTextTheme(new EditorSettings(
            styles: ['lead' => ['label' => 'Lead', 'element' => 'p', 'classes' => ['lead']]],
            fontSizes: ['small' => ['label' => 'Small', 'class' => 'size-small']],
        ), '');

        self::assertSame(['size-small'], $theme->fontSizeClasses());
        self::assertSame(EditorClasses::DEFAULT_FONT_SIZES, new RichTextTheme(new EditorSettings(), '')->fontSizeClasses());
        self::assertSame(
            '<p><span class="size-small" style="color: red">a</span>b</p>',
            $theme->filter('<p class="lead"><span class="size-small text-big" style="color:red">a</span><a href="/x">b</a></p>', true),
        );
        self::assertNull($theme->filter('<hr />', true));
    }

    public function testTheFixtureThemesTemplatesSurviveTheSanitizerUnchanged(): void
    {
        putenv('APP_DEBUG=0');
        $theme = Application::fromSite(__DIR__ . '/../../Fixtures/site')->container()->service(RichTextTheme::class);

        self::assertSame(['lead', 'table-striped', 'note', 'note-title'], $theme->themeClasses());
        self::assertNotEmpty($theme->templates());
        foreach ($theme->templates() as $template) {
            self::assertSame($template['html'], new RichTextSanitizer($theme->classes(), $theme->fontFamilies())->sanitize($template['html']), $template['label']);
        }
    }
}
