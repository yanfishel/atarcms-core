<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\View;

use AtarCms\Kernel\Application;
use AtarCms\Kernel\ConfigException;
use AtarCms\Media\Share\ShareLayout;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\View\ThemeConfig;
use AtarCms\View\ThemeDirection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThemeConfigTest extends TestCase
{
    use TempDirectory;

    public function testLoadsMenuLocationsFromThemePhp(): void
    {
        $this->writeFile('theme/theme.php', "<?php\n\nreturn ['menus' => ['main' => 'site.menus.main', 'footer' => 'Footer']];\n");

        $config = ThemeConfig::load($this->tempDir() . '/theme');

        self::assertSame(['main' => 'site.menus.main', 'footer' => 'Footer'], $config->menus);
        self::assertTrue($config->hasMenu('footer'));
        self::assertFalse($config->hasMenu('sidebar'));
    }

    public function testTheDirectionDefaultsToBothAndMayBeLeftToRightOnly(): void
    {
        self::assertSame(ThemeDirection::Both, ThemeConfig::fromArray([])->direction);
        self::assertSame(ThemeDirection::Both, ThemeConfig::fromArray(['direction' => 'both'])->direction);
        self::assertSame(ThemeDirection::Ltr, ThemeConfig::fromArray(['direction' => 'ltr'])->direction);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDirections(): iterable
    {
        yield 'rtl' => ['rtl'];
        yield 'empty' => [''];
        yield 'boolean' => [true];
    }

    #[DataProvider('invalidDirections')]
    public function testRejectsAnInvalidDirection(mixed $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('theme.php: "direction" must be "both" or "ltr"');

        ThemeConfig::fromArray(['direction' => $value]);
    }

    public function testTheShareImageLayoutDefaultsToTheBand(): void
    {
        self::assertSame(ShareLayout::Band, ThemeConfig::fromArray([])->shareLayout);
        self::assertSame(ShareLayout::Band, new ThemeConfig()->shareLayout);
        self::assertSame(ShareLayout::Band, ThemeConfig::fromArray(['share_image' => []])->shareLayout);
        foreach (ShareLayout::cases() as $layout) {
            self::assertSame($layout, ThemeConfig::fromArray(['share_image' => ['layout' => $layout->value]])->shareLayout);
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidShareImages(): iterable
    {
        yield 'not an array' => ['band', 'theme.php: "share_image" must be an array such as [\'layout\' => \'band\']'];
        yield 'unknown layout' => [['layout' => 'centre'], 'theme.php: unknown share image layout "centre"; use band, bottom or panel'];
        yield 'unknown key' => [['style' => 'band'], 'theme.php: unknown key "style" under "share_image"; known keys: layout'];
    }

    #[DataProvider('invalidShareImages')]
    public function testRejectsAnInvalidShareImageLayout(mixed $value, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        ThemeConfig::fromArray(['share_image' => $value]);
    }

    public function testAThemeWithoutThemePhpHasNoMenuLocations(): void
    {
        self::assertSame([], ThemeConfig::load($this->tempDir())->menus);
        self::assertSame([], ThemeConfig::fromArray([])->menus);
        self::assertSame([], new ThemeConfig()->menus);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'unknown key' => [['menu' => ['main' => 'Main']], 'Unknown key "menu" in theme.php'];
        yield 'menus not an array' => [['menus' => 'main'], '"menus" must be an array'];
        yield 'uppercase location' => [['menus' => ['Main' => 'Main']], 'Invalid menu location "Main"'];
        yield 'a list instead of a map' => [['menus' => ['main']], 'Invalid menu location "0"'];
        yield 'empty label' => [['menus' => ['main' => ' ']], 'needs a label'];
        yield 'label not a string' => [['menus' => ['main' => ['Main']]], 'needs a label'];
        yield 'images not an array' => [['images' => 'thumb'], '"images" must be an array'];
        yield 'reserved variant' => [['images' => ['admin' => ['width' => 100]]], 'The image variant name "admin" is reserved'];
        yield 'reserved icon variant' => [['images' => ['icon_192' => ['width' => 100]]], 'The image variant name "icon_192" is reserved for the site icon'];
        yield 'invalid variant' => [['images' => ['thumb' => ['width' => 0]]], '"width" must be an integer between 1 and 4096'];
        yield 'variant list' => [['images' => [['width' => 100]]], 'Invalid image variant name "0"'];
    }

    /**
     * @param array<mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidConfig(array $config, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        ThemeConfig::fromArray($config);
    }

    public function testThemePhpMustReturnAnArray(): void
    {
        $this->writeFile('theme/theme.php', "<?php\n\nreturn 'menus';\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must return an array');

        ThemeConfig::load($this->tempDir() . '/theme');
    }

    public function testTheActiveThemeIsAContainerService(): void
    {
        putenv('APP_DEBUG=0');
        $container = Application::fromSite(__DIR__ . '/../../Fixtures/site')->container();

        self::assertSame(['main' => 'Main menu', 'footer' => 'site.menus.footer'], $container->service(ThemeConfig::class)->menus);
    }

    public function testLoadsImageVariants(): void
    {
        $config = ThemeConfig::fromArray(['images' => [
            'thumb' => ['width' => 320, 'height' => 320, 'fit' => 'crop'],
            'medium' => ['width' => 800],
        ]]);

        self::assertSame(['thumb', 'medium'], array_keys($config->images));
        self::assertSame([320, 320, 'crop'], [$config->images['thumb']->width, $config->images['thumb']->height, $config->images['thumb']->fit]);
        self::assertSame([800, null, 'contain'], [$config->images['medium']->width, $config->images['medium']->height, $config->images['medium']->fit]);
        self::assertSame([], ThemeConfig::fromArray([])->images);
    }

    public function testLoadsTheEditorSettings(): void
    {
        $this->writeFile('theme/assets/css/editor.css', '.ck-content .lead { font-size: 1.25em; }');
        $this->writeFile('theme/editor/note.html', '<div class="note"><p>Text</p></div>');
        $this->writeFile('theme/theme.php', <<<'PHP'
            <?php

            return ['editor' => [
                'css' => ['css/editor.css'],
                'styles' => [
                    'lead' => ['label' => 'site.editor.lead', 'element' => 'p', 'classes' => ['lead']],
                    'striped' => ['label' => 'Striped table', 'element' => 'table', 'classes' => ['table-striped', 'table-wide', 'table-striped']],
                ],
                'templates' => ['note' => ['label' => 'Note', 'file' => 'editor/note.html']],
            ]];
            PHP);

        $editor = ThemeConfig::load($this->tempDir() . '/theme')->editor;

        self::assertSame(['css/editor.css'], $editor->css);
        self::assertSame([
            'lead' => ['label' => 'site.editor.lead', 'element' => 'p', 'classes' => ['lead']],
            'striped' => ['label' => 'Striped table', 'element' => 'table', 'classes' => ['table-striped', 'table-wide']],
        ], $editor->styles);
        self::assertSame(['note' => ['label' => 'Note', 'file' => 'editor/note.html']], $editor->templates);
    }

    public function testAThemeWithoutAnEditorKeyHasNoEditorSettings(): void
    {
        foreach ([ThemeConfig::fromArray([]), ThemeConfig::fromArray(['editor' => []]), ThemeConfig::load($this->tempDir()), new ThemeConfig()] as $config) {
            self::assertSame([[], [], []], [$config->editor->css, $config->editor->styles, $config->editor->templates]);
        }
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidEditorConfigs(): iterable
    {
        $style = ['label' => 'Lead', 'element' => 'p', 'classes' => ['lead']];
        $template = ['label' => 'Note', 'file' => 'editor/note.html'];

        yield 'editor not an array' => [['editor' => 'css'], '"editor" must be an array'];
        yield 'unknown editor key' => [['editor' => ['style' => []]], 'Unknown key "style" under "editor" in theme.php; known keys: css, styles, templates, colors, fonts, fontSizes, markers'];
        yield 'css not a list' => [['editor' => ['css' => ['main' => 'css/editor.css']]], '"editor.css" must be a list'];
        yield 'css absolute' => [['editor' => ['css' => ['/css/editor.css']]], 'Invalid editor stylesheet "/css/editor.css"'];
        yield 'css parent segment' => [['editor' => ['css' => ['css/../editor.css']]], 'Invalid editor stylesheet "css/../editor.css"'];
        yield 'css backslash' => [['editor' => ['css' => ['css\\editor.css']]], 'Invalid editor stylesheet'];
        yield 'css other extension' => [['editor' => ['css' => ['css/editor.scss']]], 'Invalid editor stylesheet "css/editor.scss"'];
        yield 'css not a string' => [['editor' => ['css' => [1]]], 'Invalid editor stylesheet int'];
        yield 'css missing file' => [['editor' => ['css' => ['css/missing.css']]], 'The editor stylesheet "css/missing.css" in theme.php does not exist in'];
        yield 'styles not an array' => [['editor' => ['styles' => 'lead']], '"editor.styles" must be an array'];
        yield 'style name' => [['editor' => ['styles' => ['Lead' => $style]]], 'Invalid editor style name "Lead"'];
        yield 'style list' => [['editor' => ['styles' => [$style]]], 'Invalid editor style name "0"'];
        yield 'style not an array' => [['editor' => ['styles' => ['lead' => 'p.lead']]], 'The editor style "lead" in theme.php must be an array with the keys label, element, classes'];
        yield 'style unknown key' => [['editor' => ['styles' => ['lead' => [...$style, 'attributes' => []]]]], 'Unknown key "attributes" in the editor style "lead"'];
        yield 'style element div' => [['editor' => ['styles' => ['lead' => [...$style, 'element' => 'div']]]], '"element" must be one of p, h2, h3, h4, table'];
        yield 'style element missing' => [['editor' => ['styles' => ['lead' => ['label' => 'Lead', 'classes' => ['lead']]]]], '"element" must be one of'];
        yield 'style without classes' => [['editor' => ['styles' => ['lead' => [...$style, 'classes' => []]]]], '"classes" must list 1 to 4 class names'];
        yield 'style classes not a list' => [['editor' => ['styles' => ['lead' => [...$style, 'classes' => 'lead']]]], '"classes" must list'];
        yield 'style class syntax' => [['editor' => ['styles' => ['lead' => [...$style, 'classes' => ['1lead']]]]], '"classes" must list'];
        yield 'style class with a space' => [['editor' => ['styles' => ['lead' => [...$style, 'classes' => ['a b']]]]], '"classes" must list'];
        yield 'style too many classes' => [['editor' => ['styles' => ['lead' => [...$style, 'classes' => ['a', 'b', 'c', 'd', 'e']]]]], '"classes" must list'];
        yield 'style class trailing newline' => [['editor' => ['styles' => ['lead' => [...$style, 'classes' => ["lead\n"]]]]], '"classes" must list'];
        yield 'style name trailing newline' => [['editor' => ['styles' => ["lead\n" => $style]]], 'Invalid editor style name'];
        yield 'template name trailing newline' => [['editor' => ['templates' => ["note\n" => $template]]], 'Invalid editor template name'];
        yield 'css trailing newline' => [['editor' => ['css' => ["css/editor.css\n"]]], 'Invalid editor stylesheet'];
        yield 'template file trailing newline' => [['editor' => ['templates' => ['note' => [...$template, 'file' => "editor/note.html\n"]]]], '"file" must be a relative path'];
        yield 'style without label' => [['editor' => ['styles' => ['lead' => ['element' => 'p', 'classes' => ['lead']]]]], 'The editor style "lead" in theme.php needs a label'];
        yield 'style empty label' => [['editor' => ['styles' => ['lead' => [...$style, 'label' => ' ']]]], 'needs a label'];
        yield 'templates not an array' => [['editor' => ['templates' => 'note']], '"editor.templates" must be an array'];
        yield 'template name' => [['editor' => ['templates' => ['note-block' => $template]]], 'Invalid editor template name "note-block"'];
        yield 'template unknown key' => [['editor' => ['templates' => ['note' => [...$template, 'html' => '<p>x</p>']]]], 'Unknown key "html" in the editor template "note"'];
        yield 'template without label' => [['editor' => ['templates' => ['note' => ['file' => 'editor/note.html']]]], 'The editor template "note" in theme.php needs a label'];
        yield 'template file absolute' => [['editor' => ['templates' => ['note' => [...$template, 'file' => '/etc/passwd.html']]]], '"file" must be a relative path to an .html file'];
        yield 'template file parent' => [['editor' => ['templates' => ['note' => [...$template, 'file' => '../note.html']]]], '"file" must be a relative path'];
        yield 'template file php' => [['editor' => ['templates' => ['note' => [...$template, 'file' => 'editor/note.php']]]], '"file" must be a relative path'];
        yield 'template file missing' => [['editor' => ['templates' => ['note' => [...$template, 'file' => 'editor/missing.html']]]], 'The editor template file "editor/missing.html" in theme.php does not exist in'];
        yield 'template file too large' => [['editor' => ['templates' => ['note' => [...$template, 'file' => 'editor/large.html']]]], 'The editor template file "editor/large.html" in theme.php is larger than 64 KB'];
        yield 'template file not UTF-8' => [['editor' => ['templates' => ['note' => [...$template, 'file' => 'editor/latin1.html']]]], 'The editor template file "editor/latin1.html" in theme.php is not valid UTF-8; save it as UTF-8'];
        yield 'style label twice' => [
            ['editor' => ['styles' => ['lead' => $style, 'intro' => [...$style, 'classes' => ['intro']]]]],
            'The editor style "intro" in theme.php repeats the label "Lead" of the style "lead"; the editor tells its styles apart by their labels',
        ];
    }

    public function testStylesWithLabelsThatDifferOnlyInCaseAreDistinct(): void
    {
        $styles = ThemeConfig::fromArray(['editor' => ['styles' => [
            'lead' => ['label' => 'Lead', 'element' => 'p', 'classes' => ['lead']],
            'small_lead' => ['label' => 'lead', 'element' => 'p', 'classes' => ['small-lead']],
        ]]])->editor->styles;

        self::assertSame(['Lead', 'lead'], array_column($styles, 'label'));
    }

    /**
     * @param array<mixed> $config
     */
    #[DataProvider('invalidEditorConfigs')]
    public function testRejectsInvalidEditorConfig(array $config, string $message): void
    {
        $this->writeFile('assets/css/editor.css', '');
        $this->writeFile('editor/note.html', '<p>Note</p>');
        $this->writeFile('editor/large.html', '<p>' . str_repeat('a', 65536) . '</p>');
        $this->writeFile('editor/latin1.html', "<p>Caf\xE9</p>");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        ThemeConfig::fromArray($config, $this->tempDir());
    }

    public function testTheFixtureThemeDeclaresImageVariants(): void
    {
        putenv('APP_DEBUG=0');
        $container = Application::fromSite(__DIR__ . '/../../Fixtures/site')->container();

        self::assertSame(['thumb', 'medium', 'large'], array_keys($container->service(ThemeConfig::class)->images));
    }
}
