<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Http\Security\SecurityHeadersMiddleware;
use AtarCms\Tests\Support\Regex;
use AtarCms\View\EditorClasses;
use PHPUnit\Framework\TestCase;

/**
 * The rich-text editor: CKEditor 5 vendored byte-exact (an upgrade means new files with the new version in their
 * names and new hashes here), and the glue module editor.js under the admin's CSP rules.
 */
final class EditorAssetsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private const string VENDOR = self::ROOT . '/resources/admin/assets/vendor/';

    private const array PINNED = [
        'ckeditor5-48.5.2.js' => '46e956bfcb9f3329b3172782bf68bd3b8eb61bb1ceb22e26f97e0bd9fbd2f522',
        'ckeditor5-48.5.2.css' => 'f74e84565f0e03652397117e00c588ea71044a7a5bb3e90c0652922eba2e7a4e',
        'ckeditor5-48.5.2-ru.js' => '91a77c674682dc5cca3dbdebfae4027c25254c1217b4e1d19d4436486947209c',
        'ckeditor5-48.5.2-he.js' => '449ed6aa2bbe0be219d8dd412d14e909b0effe00d2da3bc90f3482b6f5e062bc',
        'ckeditor5-48.5.2-de.js' => '1cdc25862c58878009cce3396dabbaafabfd5e3e734a30da20ed0c2bc192513a',
        'ckeditor5-48.5.2-fr.js' => 'def04e35caf32805632648571619ef8af83e965b6c3fdb9cf0a34df181dc36ed',
    ];

    public function testTheVendoredEditorKeepsItsPinnedBytesAndItsLicence(): void
    {
        foreach (self::PINNED as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', self::VENDOR . $file), $file);
        }
        self::assertStringContainsString('GNU General Public License', (string) file_get_contents(self::VENDOR . 'ckeditor5.COPYING.GPL'));
        self::assertStringContainsString('GPL', (string) file_get_contents(self::VENDOR . 'ckeditor5.LICENSE'));
        self::assertSame([], glob(self::VENDOR . '*.map') ?: [], 'no source maps');
    }

    /**
     * The classes and fonts the server keeps for the editor's features (EditorClasses) are those the vendored build
     * writes: an upgrade that renames one fails here, not on a public page.
     */
    public function testTheEditorsClassesAndDefaultsAreThoseOfTheVendoredBuild(): void
    {
        $bundle = (string) file_get_contents(self::VENDOR . 'ckeditor5-48.5.2.js');

        // image-style-full is not CKEditor's: editor.js declares that image style itself.
        $classes = array_diff([...EditorClasses::FIGURES, ...EditorClasses::IMAGE, ...EditorClasses::TODO, ...EditorClasses::TABLE, ...EditorClasses::DEFAULT_FONT_SIZES, ...EditorClasses::DEFAULT_MARKERS], ['image-style-full']);
        foreach ($classes as $class) {
            self::assertTrue(str_contains($bundle, '`' . $class . '`'), $class);
        }
        foreach (EditorClasses::DEFAULT_FONTS as $font) {
            self::assertTrue(str_contains($bundle, '`' . str_replace("'", '', $font) . '`'), $font);
        }
        self::assertTrue(str_contains($bundle, 'e.class===void 0&&(e.class=`language-${e.language}`)'), 'code blocks write language-{name}');
        self::assertTrue(str_contains($bundle, 'e.label===`Plain text`&&(e.label=t(`Plain text`))'), '"Plain text" is translated');
    }

    /**
     * The CSP allows the two <style> elements of the editor's colour picker by hash
     * (SecurityHeadersMiddleware::STYLE_HASHES): the shadow-root stylesheet of the hex-color-picker element, joined
     * from three CSS strings, and the focus rule ColorPickerView appends. Both are recomputed from the build.
     */
    public function testTheCspHashesAreThoseOfTheColourPickersStyles(): void
    {
        $bundle = (string) file_get_contents(self::VENDOR . 'ckeditor5-48.5.2.js');

        $parts = Regex::match('/get\[(\w+)\]\(\)\{return\[(\w+),(\w+),(\w+)\]\}get\[\w+\]\(\)\{return\[\w+,\w+\]\}get color\(\)/', $bundle, 'the picker joins three CSS strings');
        self::assertTrue(str_contains($bundle, '<style>${this[' . $parts[1] . '].join(``)}</style>'), 'into one <style> element');
        $sheet = '';
        foreach ([$parts[2], $parts[3], $parts[4]] as $name) {
            $css = Regex::match('/[,;]' . preg_quote($name, '/') . '=`([^`]*)`/', $bundle, $name);
            $sheet .= $css[1];
        }
        self::assertSame(1237, strlen($sheet));
        $focus = '[role="slider"]:focus [part$="pointer"] {border: 1px solid #fff;outline: 1px solid var(--ck-color-focus-border);box-shadow: 0 0 0 2px #fff;}';
        self::assertTrue(str_contains($bundle, 'e.textContent=`' . $focus . '`,this.picker.shadowRoot.appendChild(e)'), 'the focus rule');

        self::assertSame(
            ['sha256-' . base64_encode(hash('sha256', $sheet, true)), 'sha256-' . base64_encode(hash('sha256', $focus, true))],
            SecurityHeadersMiddleware::STYLE_HASHES,
        );
    }

    public function testEveryVendoredFileKeepsItsBytes(): void
    {
        $attributes = (string) file_get_contents(self::ROOT . '/.gitattributes');
        $files = glob(self::VENDOR . '*') ?: [];

        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringContainsString('/resources/admin/assets/vendor/' . basename($file) . ' -text', $attributes, basename($file));
        }
    }

    public function testTheEditorModuleImportsTheVersionedBuildAndNeverInjectsMarkup(): void
    {
        $module = (string) file_get_contents(self::ROOT . '/resources/admin/assets/editor.js');

        self::assertStringContainsString("} from './vendor/ckeditor5-48.5.2.js';", $module);
        self::assertStringContainsString("ru: () => import('./vendor/ckeditor5-48.5.2-ru.js'),", $module, 'a translation loads only for its interface language');
        self::assertStringContainsString("he: () => import('./vendor/ckeditor5-48.5.2-he.js'),", $module);
        self::assertStringContainsString("de: () => import('./vendor/ckeditor5-48.5.2-de.js'),", $module);
        self::assertStringContainsString("fr: () => import('./vendor/ckeditor5-48.5.2-fr.js'),", $module);
        foreach (array_diff(\AtarCms\I18n\InterfaceLanguages::ALL, ['en']) as $language) {
            self::assertStringContainsString($language . ": () => import('./vendor/ckeditor5-48.5.2-" . $language . ".js'),", $module, 'every interface language has the editor\'s translation: ' . $language);
            self::assertArrayHasKey('ckeditor5-48.5.2-' . $language . '.js', self::PINNED, $language);
        }
        self::assertStringContainsString('Object.hasOwn(TRANSLATIONS, settings.language)', $module);
        self::assertStringContainsString("licenseKey: 'GPL'", $module);
        self::assertStringContainsString("request.setRequestHeader('X-CSRF-Token', csrfToken())", $module);
        self::assertStringContainsString('previewsInData: false', $module, 'videos are stored as <oembed>, never as players');
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', 'http://', 'https://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $module, $forbidden);
        }
        self::assertDoesNotMatchRegularExpression('/\.style\b/', $module);
        self::assertSame(1, substr_count($module, '<iframe'), 'the video preview is the only markup string');
        self::assertStringContainsString('<iframe class="admin-editor-video" src="${src}"', $module);
        self::assertDoesNotMatchRegularExpression('/<iframe[^`]*style/', $module);
    }

    public function testUploadsAndSavingFollowTheRules(): void
    {
        $module = (string) file_get_contents(self::ROOT . '/resources/admin/assets/editor.js');

        self::assertStringContainsString("media.mime.startsWith('image/')", $module, 'a non-image is refused');
        self::assertStringContainsString("body.append('files[]', file);", $module);
        self::assertStringContainsString("request.setRequestHeader('Accept', 'application/json');", $module);
        self::assertStringContainsString("editor.plugins.get('PendingActions').hasAny", $module, 'no saving mid-upload');
        self::assertStringContainsString('source.updateEditorData();', $module, 'source mode saves what was typed');
        self::assertStringContainsString("on('show:warning'", $module, 'no alert()');
        self::assertStringNotContainsString('alert(', $module);
        self::assertStringContainsString("new CustomEvent('media-pick', { detail: { locale: contentLocale } })", $module);
        self::assertStringContainsString("document.addEventListener('media-picked'", $module);
        self::assertStringContainsString('Essentials, Notification, Paragraph,', $module, 'the warnings work without uploads too');
    }

    /**
     * Style attributes apply under the CSP now (style-src-attr): the Plan 9 workarounds against CKEditor's inline
     * styles are gone, so the upload progress bar works and images keep their aspect ratio in the editing view.
     */
    public function testTheInlineStyleWorkaroundsAreGone(): void
    {
        $module = (string) file_get_contents(self::ROOT . '/resources/admin/assets/editor.js');

        self::assertStringNotContainsString('uploadedPercent', $module);
        self::assertStringNotContainsString('aspect-ratio', $module);
        self::assertStringNotContainsString('removeStyle', $module);
        self::assertStringNotContainsString('ck-progress-bar', (string) file_get_contents(self::ROOT . '/resources/admin/assets/admin.css'));
    }

    /**
     * The full toolbar of the addendum spec (§4, §14): every plugin by its export name, every toolbar item, the grouped layout,
     * classes for alignment and indentation, list styles as attributes, hex colours from the picker, and the word
     * count under the editor before the message line.
     */
    public function testTheEditorHasTheFullToolbar(): void
    {
        $module = (string) file_get_contents(self::ROOT . '/resources/admin/assets/editor.js');
        preg_match('/^import \{([^}]*)\} from/m', $module, $import);
        $imported = array_map(trim(...), explode(',', $import[1] ?? ''));

        foreach ([
            'Underline', 'Strikethrough', 'Subscript', 'Superscript', 'Code', 'RemoveFormat', 'FontSize', 'FontFamily',
            'FontColor', 'FontBackgroundColor', 'Highlight', 'Alignment', 'Indent', 'IndentBlock', 'ListProperties',
            'TodoList', 'CodeBlock', 'HorizontalLine', 'ImageStyle', 'ImageResize', 'LinkImage', 'AutoLink', 'TableCaption',
            'TableProperties', 'TableCellProperties', 'TableColumnResize', 'SpecialCharacters', 'SpecialCharactersEssentials',
            'TextPartLanguage', 'FindAndReplace', 'ShowBlocks', 'Fullscreen', 'WordCount',
        ] as $plugin) {
            self::assertContains($plugin, $imported, $plugin);
            self::assertMatchesRegularExpression('/\b' . $plugin . '\b/', substr($module, (int) strpos($module, 'const plugins = [')), $plugin . ' is loaded');
        }
        foreach ([
            'undo', 'redo', 'heading', 'style', 'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor', 'highlight',
            'bold', 'italic', 'underline', 'strikethrough', 'subscript', 'superscript', 'code', 'removeFormat', 'alignment',
            'bulletedList', 'numberedList', 'todoList', 'outdent', 'indent', 'link', 'mediaLibrary', 'insertTable',
            'mediaEmbed', 'templates', 'blockQuote', 'codeBlock', 'horizontalLine', 'specialCharacters', 'textPartLanguage',
            'findAndReplace', 'selectAll', 'showBlocks', 'sourceEditing', 'fullscreen', 'imageStyle:inline',
            'imageStyle:alignLeft', 'imageStyle:block', 'imageStyle:alignRight', 'imageStyle:full', 'resizeImage',
            'imageTextAlternative', 'toggleImageCaption', 'linkImage', 'tableColumn', 'tableRow', 'mergeTableCells',
            'tableProperties', 'tableCellProperties', 'toggleTableCaption',
        ] as $item) {
            self::assertStringContainsString("'" . $item . "'", $module, $item);
        }
        self::assertStringContainsString(<<<'JS'
            const TOOLBAR = [
                'undo', 'redo', '|', 'heading', 'style', '|', 'bold', 'italic', 'fontSize', 'fontColor',
                {
                    group: 'formatting',
                    icon: 'text',
                    items: [
                        'underline', 'strikethrough', 'subscript', 'superscript', 'code', '|', 'fontFamily', 'fontBackgroundColor',
                        'highlight', '|', 'outdent', 'indent', '|', 'textPartLanguage', 'removeFormat',
                    ],
                },
                '|', 'alignment',
                '-',
                'bulletedList', 'numberedList', 'todoList', '|',
                'link', 'mediaLibrary', 'mediaEmbed', 'insertTable', 'templates',
                { group: 'insert', icon: 'plus', items: ['blockQuote', 'codeBlock', 'horizontalLine', 'specialCharacters'] },
                '|', { group: 'tools', icon: 'threeVerticalDots', items: ['findAndReplace', 'selectAll', 'showBlocks'] },
                'sourceEditing', 'fullscreen',
            ];
            JS, $module, 'two rows, measured at 1280 and 1440 px (the plan records the measurements)');
        self::assertStringContainsString('items.push({ label: labels[item.group], icon: item.icon, items: grouped });', $module, 'groups take their translated label; empty ones go');
        self::assertStringContainsString("button.set({ label: settings.labels.library, icon: IconImageAssetManager, tooltip: true });", $module, 'icon buttons keep the toolbar short');
        self::assertStringContainsString("dropdown.buttonView.set({ label: settings.labels.templates, icon: IconTemplate, tooltip: true });", $module);
        self::assertStringContainsString('shouldNotGroupWhenFull: true', $module);
        self::assertStringContainsString('className: `text-align-${name}`', $module);
        self::assertStringContainsString("const INDENT_CLASSES = ['indent-1', 'indent-2', 'indent-3', 'indent-4'];", $module);
        self::assertStringContainsString('useAttribute: true', $module);
        self::assertStringContainsString("format: 'hex'", $module);
        self::assertStringContainsString("attributes: { target: '_blank', rel: 'noopener noreferrer' }", $module, 'in the order the server writes rel');
        self::assertStringContainsString("className: 'image-style-full'", $module);
        self::assertStringContainsString("resizeUnit: '%'", $module);
        self::assertStringContainsString('fullscreen: { menuBar: { isVisible: false } }', $module);
        self::assertStringContainsString("const wordCount = editor.plugins.get('WordCount').wordCountContainer;\n    editor.ui.element.after(wordCount);\n    messages = messageLine(wordCount);", $module);
        self::assertStringContainsString("'tr', 'caption', 'th', 'td', 'a', 'span', 'mark',", $module, 'theme classes on spans and marks');
    }

    /**
     * A minimal field (Field::minimal()) gets bold, italic, font size and colour, and paragraphs: the plugins the
     * server's minimal profile can store, nothing else.
     */
    public function testAMinimalFieldGetsTheMinimalToolbar(): void
    {
        $module = (string) file_get_contents(self::ROOT . '/resources/admin/assets/editor.js');

        self::assertStringContainsString("const MINIMAL_TOOLBAR = ['undo', 'redo', '|', 'bold', 'italic', 'fontSize', 'fontColor'];", $module);
        self::assertStringContainsString("const minimal = settings.profile === 'minimal';", $module);
        self::assertStringContainsString('const plugins = minimal ? [Essentials, Notification, Paragraph, Bold, Italic, FontColor, WordCount, PasteFromOffice] : [', $module);
        self::assertStringContainsString('const optional = minimal ? [fontSize] : [', $module);
        self::assertStringContainsString('if (settings.upload && !minimal) {', $module, 'no image uploads into a summary');
        self::assertStringContainsString("toolbar: { items: toolbarItems(minimal ? MINIMAL_TOOLBAR : TOOLBAR, off, settings.labels), shouldNotGroupWhenFull: true },", $module);
        self::assertStringContainsString("editor.plugins.has('SourceEditing')", $module, 'the submit handler works without source editing');
        self::assertStringContainsString("editor.plugins.has('PendingActions') && editor.plugins.get('PendingActions').hasAny", $module, 'a minimal editor loads no PendingActions: nothing uploads');
    }

    /**
     * A field with ->max() (data-max) shows "412 / 500" in the word count line, counted like RichText::text() and
     * mb_strlen(): blocks and soft breaks are one space, whitespace runs collapse, the ends are trimmed, code points
     * count. CKEditor's own character count is off there, so two numbers never disagree.
     */
    public function testALimitedFieldCountsItsVisibleTextLikeTheServer(): void
    {
        $module = (string) file_get_contents(self::ROOT . '/resources/admin/assets/editor.js');

        self::assertStringContainsString("const limit = Number.parseInt(textarea.dataset.max ?? '', 10);", $module);
        self::assertStringContainsString('wordCount: { displayWords: true, displayCharacters: !counted },', $module);
        self::assertStringContainsString("for (const item of editor.model.createRangeIn(editor.model.document.getRoot()).getItems()) {", $module, 'the model, block by block');
        self::assertStringContainsString("parts.push(item.is('\$textProxy') ? item.data : item.is('element', 'imageInline') ? '' : ' ');", $module, 'an inline image is no text (strip_tags drops <img>), every other element one space');
        self::assertStringContainsString("return [...parts.join('').replace(/\\s+/gu, ' ').trim()].length;", $module, 'collapsed, trimmed, code points');
        self::assertStringContainsString("item.className = 'admin-editor-count';", $module);
        self::assertStringContainsString("item.classList.toggle('admin-editor-count-over', over);", $module);
        self::assertStringContainsString("status.role = 'status';", $module, 'only the crossing of the limit is announced');
        self::assertStringContainsString("visible.dir = 'ltr';", $module, '"412 / 500" reads the same in a right-to-left interface');
        self::assertStringContainsString("editor.model.document.on('change:data', update);", $module);
    }

    public function testVideoHostsMatchInAnyLetterCaseAndPathsAsWritten(): void
    {
        $module = (string) file_get_contents(self::ROOT . '/resources/admin/assets/editor.js');

        self::assertStringContainsString('const anyCase = (source) => source.replace(/[a-z]/g, (letter) => `[${letter}${letter.toUpperCase()}]`);', $module);
        self::assertStringContainsString('new RegExp(`^(?:${anyCase(\'https?\')}:\\\\/\\\\/)?(?:${anyCase(host)})${path}$`)', $module);
        self::assertStringNotContainsString('anyCase(path)', $module);
    }

    public function testTrixIsGone(): void
    {
        foreach (['rich-text.js', 'vendor/trix-2.1.19.esm.min.js', 'vendor/trix.css', 'vendor/trix.LICENSE'] as $file) {
            self::assertFileDoesNotExist(self::ROOT . '/resources/admin/assets/' . $file);
        }
        self::assertStringNotContainsStringIgnoringCase('trix', (string) file_get_contents(self::ROOT . '/.gitattributes'));
    }
}
