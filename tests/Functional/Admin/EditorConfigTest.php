<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Auth\UserRepository;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Browser;
use AtarCms\View\EditorSettings;
use AtarCms\View\ThemeConfig;

/**
 * The rich-text widget carries the editor's settings (EditorConfig) in data-editor: the fixture theme declares two
 * styles, one ready-made block, an editor stylesheet, two colours, one marker and no fonts.
 */
final class EditorConfigTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Roles::class, new Roles([...Roles::DEFAULTS, 'writer' => ['label' => 'Writer', 'permissions' => ['entries.*.*']]]));
    }

    public function testAnEditorGetsTheThemesStylesBlocksAndUploads(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertSame([
            'profile' => 'full',
            'language' => 'en',
            'upload' => [
                'url' => '/admin/media',
                'failed' => 'The upload failed. Try again.',
                'pending' => 'An image is still uploading. Wait for it to finish, then save.',
            ],
            'library' => true,
            'classStyles' => [
                ['name' => 'Lead paragraph', 'element' => 'p', 'classes' => ['lead']],
                ['name' => 'Striped table', 'element' => 'table', 'classes' => ['table-striped']],
            ],
            'templates' => [['label' => 'Note', 'html' => '<div class="note"><p class="note-title">Note</p><p>Text</p></div>']],
            'classes' => ['lead', 'table-striped', 'note', 'note-title'],
            'fontSizes' => null,
            'fonts' => [],
            'colors' => [['color' => '#3a7bd5', 'label' => 'Accent'], ['color' => 'hsl(0,0%,30%)', 'label' => 'Grey']],
            'markers' => [['model' => 'note', 'class' => 'marker-note', 'title' => 'Note', 'color' => '#fdfd77', 'type' => 'marker']],
            'codeLanguages' => [
                ['language' => 'plaintext', 'label' => 'Plain text'], ['language' => 'html', 'label' => 'HTML'],
                ['language' => 'css', 'label' => 'CSS'], ['language' => 'javascript', 'label' => 'JavaScript'],
                ['language' => 'php', 'label' => 'PHP'], ['language' => 'sql', 'label' => 'SQL'], ['language' => 'bash', 'label' => 'Bash'],
                ['language' => 'json', 'label' => 'JSON'], ['language' => 'xml', 'label' => 'XML'],
            ],
            'textLanguages' => [
                ['languageCode' => 'en', 'title' => 'English'], ['languageCode' => 'ru', 'title' => 'Русский'],
                ['languageCode' => 'uk', 'title' => 'Українська'], ['languageCode' => 'de', 'title' => 'Deutsch'],
                ['languageCode' => 'fr', 'title' => 'Français'], ['languageCode' => 'es', 'title' => 'Español'],
                ['languageCode' => 'it', 'title' => 'Italiano'], ['languageCode' => 'pt', 'title' => 'Português'],
                ['languageCode' => 'pl', 'title' => 'Polski'], ['languageCode' => 'cs', 'title' => 'Čeština'],
                ['languageCode' => 'zh', 'title' => '中文'], ['languageCode' => 'ja', 'title' => '日本語'],
                ['languageCode' => 'ar', 'title' => 'العربية', 'textDirection' => 'rtl'], ['languageCode' => 'he', 'title' => 'עברית', 'textDirection' => 'rtl'],
            ],
            'video' => ['youtube' => 'https://www.youtube-nocookie.com/embed/', 'vimeo' => 'https://player.vimeo.com/video/'],
            'linkProtocol' => 'https://',
            'labels' => [
                'library' => 'Photos and files', 'templates' => 'Ready-made blocks', 'newTab' => 'Open in a new tab', 'imageFull' => 'Full width',
                'formatting' => 'More formatting', 'insert' => 'Insert', 'tools' => 'Tools',
                'count' => '#count of #max characters', 'countOver' => 'Over the limit: #count of #max characters',
            ],
        ], $this->settings($this->browser, '/admin/content/articles/new'));
    }

    public function testTheThemesFontSizesAndFontsBecomeTheEditorsOptions(): void
    {
        $this->app->container()->set(ThemeConfig::class, new ThemeConfig(editor: new EditorSettings(
            fonts: [['label' => 'site.editor.lead', 'family' => "Georgia, 'Times New Roman', serif"]],
            fontSizes: ['small' => ['label' => 'Small', 'class' => 'text-small']],
            markers: [],
        )));
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $settings = $this->settings($this->browser, '/admin/content/articles/new');

        self::assertSame([['title' => 'Lead paragraph', 'family' => "Georgia, 'Times New Roman', serif"]], $settings['fonts']);
        self::assertSame([['model' => 'small', 'title' => 'Small', 'view' => ['name' => 'span', 'classes' => 'text-small']]], $settings['fontSizes']);
        self::assertSame([], $settings['markers'], 'turned off');
        self::assertNull($settings['colors'], "the editor's palette");
    }

    public function testEveryLanguageCanBeMarkedOnASiteWithOneLanguage(): void
    {
        $this->app->container()->set(Locales::class, new Locales('en', ['en']));
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $page = (string) $this->browser->get('/admin/content/articles/new')->getBody();
        $settings = $this->settings($this->browser, '/admin/content/articles/new');

        self::assertStringNotContainsString('<nav class="admin-switch"', $page, 'the swap took effect: the form has no language switch');
        self::assertIsArray($settings['textLanguages']);
        self::assertCount(14, $settings['textLanguages']);
    }

    public function testWithoutMediaUploadThereAreNoUploadsAndNoLibrary(): void
    {
        $writer = new Browser($this->app);
        $this->actingAs($this->createUser('wr@example.com', 'writer'), $writer);

        $settings = $this->settings($writer, '/admin/content/articles/new');

        self::assertNull($settings['upload']);
        self::assertFalse($settings['library']);
    }

    public function testTheSettingsSpeakTheInterfaceLanguage(): void
    {
        $user = $this->createUser('ed@example.com', 'editor');
        $this->app->container()->service(UserRepository::class)->setLocale($user->id, 'ru');
        $this->actingAs($user);

        $settings = $this->settings($this->browser, '/admin/content/pages/new');

        self::assertSame('ru', $settings['language']);
        self::assertSame([
            'library' => 'Фото и файлы', 'templates' => 'Готовые блоки', 'newTab' => 'Открывать в новой вкладке', 'imageFull' => 'Во всю ширину',
            'formatting' => 'Ещё форматирование', 'insert' => 'Вставить', 'tools' => 'Инструменты',
            'count' => '#count из #max символов', 'countOver' => 'Больше допустимого: #count из #max символов',
        ], $settings['labels']);
        self::assertIsArray($settings['codeLanguages']);
        self::assertSame(['language' => 'plaintext', 'label' => 'Обычный текст'], $settings['codeLanguages'][0] ?? null, 'the one code language label that is a word');
        self::assertSame(['language' => 'php', 'label' => 'PHP'], $settings['codeLanguages'][4] ?? null);
        self::assertSame([['color' => '#3a7bd5', 'label' => 'Акцент'], ['color' => 'hsl(0,0%,30%)', 'label' => 'Grey']], $settings['colors']);
        self::assertSame([['model' => 'note', 'class' => 'marker-note', 'title' => 'Заметка', 'color' => '#fdfd77', 'type' => 'marker']], $settings['markers']);
        self::assertSame([
            ['name' => 'Вводный абзац', 'element' => 'p', 'classes' => ['lead']],
            ['name' => 'Striped table', 'element' => 'table', 'classes' => ['table-striped']],
        ], $settings['classStyles'], 'translation keys are translated, plain text stays');
        self::assertSame([['label' => 'Заметка', 'html' => '<div class="note"><p class="note-title">Note</p><p>Text</p></div>']], $settings['templates']);
        self::assertSame([
            'url' => '/admin/media',
            'failed' => 'Загрузка не удалась. Попробуйте ещё раз.',
            'pending' => 'Изображение ещё загружается. Дождитесь окончания и сохраните.',
        ], $settings['upload']);
    }

    /**
     * A Hebrew interface gives CKEditor its Hebrew interface (editor.js loads the vendored translation, which also
     * turns the editor's toolbar right to left).
     */
    public function testAHebrewInterfaceGetsAHebrewEditor(): void
    {
        $user = $this->createUser('ed@example.com', 'editor');
        $this->app->container()->service(UserRepository::class)->setLocale($user->id, 'he');
        $this->actingAs($user);

        $settings = $this->settings($this->browser, '/admin/content/pages/new');

        self::assertSame('he', $settings['language']);
        self::assertIsArray($settings['labels']);
        self::assertSame('תמונות וקבצים', $settings['labels']['library'] ?? null);
    }

    /**
     * The fixture's pages have a full body and a minimal teaser (Field::minimal()): each widget gets its profile, and
     * the minimal one a short no-JS textarea.
     */
    public function testAMinimalFieldGetsTheMinimalProfile(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $body = (string) $this->browser->get('/admin/content/pages/new')->getBody();

        self::assertSame('full', $this->settings($this->browser, '/admin/content/pages/new', 0)['profile']);
        $minimal = $this->settings($this->browser, '/admin/content/pages/new', 1);
        self::assertSame('minimal', $minimal['profile']);
        self::assertNull($minimal['fontSizes'], "the editor's sizes, or the theme's");
        self::assertMatchesRegularExpression('~<textarea id="field-body" name="fields\[body\]" rows="12" data-rich-text~', $body);
        self::assertMatchesRegularExpression('~<textarea id="field-teaser" name="fields\[teaser\]" rows="4" data-rich-text~', $body);
    }

    /**
     * @return array<string, mixed> the decoded data-editor of the rich-text widget number $index on $path
     */
    private function settings(Browser $browser, string $path, int $index = 0): array
    {
        $body = (string) $browser->get($path)->getBody();
        preg_match_all('~<textarea [^>]*data-rich-text[^>]* data-editor="([^"]*)"~', $body, $matches);
        self::assertArrayHasKey($index, $matches[1], 'the widget carries data-editor');
        $settings = json_decode(html_entity_decode($matches[1][$index], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($settings);

        /** @var array<string, mixed> $settings */
        return $settings;
    }
}
