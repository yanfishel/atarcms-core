<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\RichTextSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RichTextSanitizerTest extends TestCase
{
    /** What CKEditor 5 writes (getData() of the spike with styles, a template, a table, videos and images). */
    private const string EDITOR_OUTPUT = '<p class="lead">Lead</p>'
        . '<figure class="table"><table class="table-striped"><thead><tr><th>H</th></tr></thead><tbody><tr><td colspan="2">D</td></tr></tbody></table></figure>'
        . '<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'
        . '<figure class="media"><oembed url="https://vimeo.com/76979871"></oembed></figure>'
        . '<figure class="image"><img src="/uploads/a.png" alt="First image"><figcaption>Caption</figcaption></figure>'
        . '<div class="note"><p>Text</p></div>'
        . '<figure class="image"><img style="aspect-ratio:1/1;" src="/uploads/x.png" width="1" height="1"></figure>';

    public function testKeepsTheEditorsMarkupWithItsAspectRatio(): void
    {
        $stored = new RichTextSanitizer(null)->sanitize(self::EDITOR_OUTPUT);

        self::assertSame(
            '<p class="lead">Lead</p>'
            . '<figure class="table"><table class="table-striped"><thead><tr><th>H</th></tr></thead><tbody><tr><td colspan="2">D</td></tr></tbody></table></figure>'
            . '<figure class="media"><oembed url="https://www.youtube.com/watch?v&#61;dQw4w9WgXcQ"></oembed></figure>'
            . '<figure class="media"><oembed url="https://vimeo.com/76979871"></oembed></figure>'
            . '<figure class="image"><img src="/uploads/a.png" alt="First image" /><figcaption>Caption</figcaption></figure>'
            . '<div class="note"><p>Text</p></div>'
            . '<figure class="image"><img style="aspect-ratio: 1/1" src="/uploads/x.png" width="1" height="1" /></figure>',
            $stored,
        );
        self::assertSame($stored, new RichTextSanitizer(null)->sanitize($stored), 'idempotent');
        self::assertSame($stored, new RichTextSanitizer(['lead', 'table-striped', 'note', 'image', 'media', 'table'])->sanitize($stored));
    }

    /**
     * The exact getData() output of each toolbar feature of CKEditor 5 48.5.2 (taken in the browser), and how it is
     * stored: style text as "prop: value; prop: value", void elements as "<x />", entities of the sanitizer.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function features(): iterable
    {
        yield 'inline formats' => ['<p><code><s><sub><u>Hello</u></sub></s></code><sup>2</sup></p>', '<p><code><s><sub><u>Hello</u></sub></s></code><sup>2</sup></p>'];
        yield 'font colour' => ['<p><span style="color:#e64d4d;">Hello</span></p>', '<p><span style="color: #e64d4d">Hello</span></p>'];
        yield 'font background' => ['<p><span style="background-color:hsl(0, 0%, 90%);">Hello</span></p>', '<p><span style="background-color: hsl(0,0%,90%)">Hello</span></p>'];
        yield 'font family' => ['<p><span style="font-family:\'Courier New\', Courier, monospace;">Hello</span></p>', '<p><span style="font-family: &#039;Courier New&#039;, Courier, monospace">Hello</span></p>'];
        yield 'font size' => ['<p><span class="text-big">Hello</span></p>', '<p><span class="text-big">Hello</span></p>'];
        yield 'merged font attributes' => [
            '<p><span class="text-big" style="color:#e64d4d;font-family:Georgia, serif;">Hello</span></p>',
            '<p><span class="text-big" style="color: #e64d4d; font-family: Georgia, serif">Hello</span></p>',
        ];
        yield 'marker and pen' => ['<p><mark class="marker-yellow">A</mark><mark class="pen-red">B</mark></p>', '<p><mark class="marker-yellow">A</mark><mark class="pen-red">B</mark></p>'];
        yield 'alignment' => ['<p class="text-align-center">A</p><h2 class="text-align-right">B</h2>', '<p class="text-align-center">A</p><h2 class="text-align-right">B</h2>'];
        yield 'block indent' => ['<p class="indent-2">A</p>', '<p class="indent-2">A</p>'];
        yield 'list indent' => ['<ul class="indent-1"><li data-list-item-id="ee3f0ae763b44405356b2a1103e4c88af">A</li></ul>', '<ul class="indent-1"><li>A</li></ul>'];
        yield 'numbered list properties' => [
            '<ol type="a" reversed="reversed" start="3"><li data-list-item-id="e2d88b072bc1d9ebeee8081f8dba2877d">A</li></ol>',
            '<ol type="a" reversed="reversed" start="3"><li>A</li></ol>',
        ];
        yield 'list start 0' => ['<ol start="0"><li>A</li></ol>', '<ol start="0"><li>A</li></ol>'];
        yield 'bulleted list style' => ['<ul type="square"><li>A</li></ul>', '<ul type="square"><li>A</li></ul>'];
        yield 'to-do list' => [
            '<ul class="todo-list"><li data-list-item-id="ee31bd3ce9fe9a6975e0fecc3c77367dc"><label class="todo-list__label"><input type="checkbox" checked="checked" disabled="disabled"><span class="todo-list__label__description">Text</span></label></li>'
            . '<li data-list-item-id="ee125958cabfc5a79706cf1fc23f0f187"><label class="todo-list__label"><input type="checkbox" disabled="disabled"><span class="todo-list__label__description">A</span></label><p>B</p></li>'
            . '<li data-list-item-id="eded469a4166466549a9421f4b1482762"><label class="todo-list__label todo-list__label_without-description"><input type="checkbox" disabled="disabled"></label><h2>C</h2></li></ul>',
            '<ul class="todo-list"><li><label class="todo-list__label"><input type="checkbox" checked="checked" disabled="disabled" /><span class="todo-list__label__description">Text</span></label></li>'
            . '<li><label class="todo-list__label"><input type="checkbox" disabled="disabled" /><span class="todo-list__label__description">A</span></label><p>B</p></li>'
            . '<li><label class="todo-list__label todo-list__label_without-description"><input type="checkbox" disabled="disabled" /></label><h2>C</h2></li></ul>',
        ];
        yield 'code block' => ['<pre><code class="language-php">$x = 1;</code></pre>', '<pre><code class="language-php">$x &#61; 1;</code></pre>'];
        yield 'horizontal line' => ['<hr><p>A</p>', '<hr /><p>A</p>'];
        yield 'language marks' => ['<p><span lang="ar" dir="rtl">مرحبا</span> <span lang="en" dir="ltr">Hello</span></p>', '<p><span lang="ar" dir="rtl">مرحبا</span> <span lang="en" dir="ltr">Hello</span></p>'];
        yield 'link in a new tab' => ['<p><a href="https://example.com" target="_blank" rel="noopener noreferrer">Hello</a></p>', '<p><a href="https://example.com" target="_blank" rel="noopener noreferrer">Hello</a></p>'];
        yield 'image styles' => [
            '<figure class="image image-style-align-left"><img style="aspect-ratio:1600/900;" src="/uploads/a.png" alt="A" width="1600" height="900"></figure>'
            . '<figure class="image image-style-full"><img src="/uploads/a.png" alt="A"></figure>',
            '<figure class="image image-style-align-left"><img style="aspect-ratio: 1600/900" src="/uploads/a.png" alt="A" width="1600" height="900" /></figure>'
            . '<figure class="image image-style-full"><img src="/uploads/a.png" alt="A" /></figure>',
        ];
        yield 'resized block image' => [
            '<figure class="image image_resized" style="width:48.53%;"><img style="aspect-ratio:1600/900;" src="/uploads/a.png" alt="A" width="1600" height="900"></figure>',
            '<figure class="image image_resized" style="width: 48.53%"><img style="aspect-ratio: 1600/900" src="/uploads/a.png" alt="A" width="1600" height="900" /></figure>',
        ];
        yield 'resized inline image' => [
            '<p>Text <img class="image-style-align-right image_resized" style="aspect-ratio:1600/900;width:25%;" src="/uploads/a.png" alt="A" width="1600" height="900"> text</p>',
            '<p>Text <img class="image-style-align-right image_resized" style="aspect-ratio: 1600/900; width: 25%" src="/uploads/a.png" alt="A" width="1600" height="900" /> text</p>',
        ];
        yield 'linked image' => [
            '<figure class="image"><a href="/uploads/a.png"><img src="/uploads/a.png" alt="A"></a><figcaption>C</figcaption></figure>',
            '<figure class="image"><a href="/uploads/a.png"><img src="/uploads/a.png" alt="A" /></a><figcaption>C</figcaption></figure>',
        ];
        yield 'table caption and alignment' => [
            '<figure class="table table-style-align-left"><table><tbody><tr><td>A</td></tr></tbody></table><figcaption>Caption</figcaption></figure>',
            '<figure class="table table-style-align-left"><table><tbody><tr><td>A</td></tr></tbody></table><figcaption>Caption</figcaption></figure>',
        ];
        yield 'table properties' => [
            '<figure class="table" style="height:200px;width:60%;"><table style="background-color:#ffffff;border:2px dashed #999999;"><tbody><tr><td>A</td></tr></tbody></table></figure>',
            '<figure class="table" style="height: 200px; width: 60%"><table style="background-color: #ffffff; border: 2px dashed #999999"><tbody><tr><td>A</td></tr></tbody></table></figure>',
        ];
        yield 'cell properties' => [
            '<figure class="table"><table><tbody><tr><td style="background-color:hsl(120, 75%, 60%);border-color:red;height:40px;padding:4px;text-align:center;vertical-align:bottom;width:120px;">A</td><th style="border-bottom-color:#000;padding:1px 2px 3px 4px;">B</th></tr></tbody></table></figure>',
            '<figure class="table"><table><tbody><tr><td style="background-color: hsl(120,75%,60%); border-color: red; height: 40px; padding: 4px; text-align: center; vertical-align: bottom; width: 120px">A</td><th style="border-bottom-color: #000; padding: 1px 2px 3px 4px">B</th></tr></tbody></table></figure>',
        ];
        yield 'per-side borders' => [
            '<figure class="table"><table style="border-left:2px dotted blue;border-top:1px solid red;"><tbody><tr><td>A</td></tr></tbody></table></figure>',
            '<figure class="table"><table style="border-left: 2px dotted blue; border-top: 1px solid red"><tbody><tr><td>A</td></tr></tbody></table></figure>',
        ];
        yield 'resized columns' => [
            '<figure class="table" style="width:80%;"><table class="ck-table-resized"><colgroup><col style="width:33.33%;"><col style="width:66.67%;"></colgroup><tbody><tr><td>A</td><td>B</td></tr></tbody></table></figure>',
            '<figure class="table" style="width: 80%"><table class="ck-table-resized"><colgroup><col style="width: 33.33%" /><col style="width: 66.67%" /></colgroup><tbody><tr><td>A</td><td>B</td></tr></tbody></table></figure>',
        ];
    }

    #[DataProvider('features')]
    public function testStoresWhatEachFeatureWrites(string $editorOutput, string $stored): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame($stored, $sanitizer->sanitize($editorOutput));
        self::assertSame($stored, $sanitizer->sanitize($stored), 'idempotent');
    }

    public function testADocumentWithEveryFeatureIsStoredOnceAndForAll(): void
    {
        $features = iterator_to_array(self::features());
        $sanitizer = new RichTextSanitizer(null);
        $stored = $sanitizer->sanitize(implode('', array_column($features, 0)));

        self::assertSame(implode('', array_column($features, 1)), $stored);
        self::assertSame($stored, $sanitizer->sanitize($stored));
    }

    public function testStoresASchemeLessVideoLinkTheEditorPreviewsInCanonicalForm(): void
    {
        $stored = new RichTextSanitizer(null)->sanitize('<figure class="media"><oembed url="youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>');

        self::assertSame('<figure class="media"><oembed url="https://www.youtube.com/watch?v&#61;dQw4w9WgXcQ"></oembed></figure>', $stored);
    }

    public function testAllowsOnlyTheListedAttributesPerElement(): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame('<a href="https://example.com/x" title="t" class="button" target="_blank" rel="noopener">x</a>', $sanitizer->sanitize('<a href="https://example.com/x" title="t" class="button" target="_blank" rel="noopener" id="a" data-x="1" style="color:red">x</a>'));
        self::assertSame('<img class="wide" src="/a.png" alt="a" title="t" width="10" height="20" />', $sanitizer->sanitize('<img class="wide" src="/a.png" alt="a" title="t" width="10" height="20" srcset="/b.png 2x" loading="lazy">'));
        self::assertSame('<table><tbody><tr><th colspan="2" class="x">H</th><td rowspan="3" class="y" style="width: 10px">D</td></tr></tbody></table>', $sanitizer->sanitize('<table><tbody><tr><th colspan="2" class="x" scope="col">H</th><td rowspan="3" class="y" style="width:10px">D</td></tr></tbody></table>'));
        self::assertSame('<p>Hi</p>', $sanitizer->sanitize('<p id="x" style="color:red" onclick="steal()" data-id="1" lang="en" dir="rtl">Hi</p>'));
        self::assertSame('<h3 class="title">T</h3><ul class="list"><li class="item">i</li></ul>', $sanitizer->sanitize('<h3 class="title">T</h3><ul class="list"><li class="item" style="color:red">i</li></ul>'));
        self::assertSame('<oembed url="https://vimeo.com/1"></oembed>', $sanitizer->sanitize('<oembed url="https://vimeo.com/1" class="x" style="y"></oembed>'));
        self::assertSame(
            '<table class="t"><colgroup class="c"><col /></colgroup><tbody><tr><td>a</td></tr></tbody></table>',
            $sanitizer->sanitize('<table class="t"><colgroup class="c"><col class="c" span="2" style="color:red"></colgroup><tbody><tr><td>a</td></tr></tbody></table>'),
            'col keeps only its width',
        );
        self::assertSame('<p><mark class="x">a</mark><label class="y">b</label><span class="z">c</span></p>', $sanitizer->sanitize('<p><mark class="x" style="color:red">a</mark><label class="y" for="i">b</label><span class="z" title="t">c</span></p>'));
    }

    public function testListAttributesKeepOnlyTheirValues(): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame('<ol type="I" start="12" reversed="reversed"><li>a</li></ol>', $sanitizer->sanitize('<ol type="I" start="012" reversed><li>a</li></ol>'));
        self::assertSame('<ol><li>a</li></ol>', $sanitizer->sanitize('<ol type="disc" start="-1" data-x="1"><li>a</li></ol>'));
        self::assertSame('<ol><li>a</li></ol>', $sanitizer->sanitize('<ol start="1000000"><li>a</li></ol>'), 'start is 0 to 999999');
        self::assertSame('<ol start="999999"><li>a</li></ol>', $sanitizer->sanitize('<ol start="999999"><li>a</li></ol>'));
        self::assertSame('<ol><li>a</li></ol>', $sanitizer->sanitize('<ol start="1.5" type="decimal-leading-zero"><li>a</li></ol>'));
        self::assertSame('<ul type="circle"><li>a</li></ul>', $sanitizer->sanitize('<ul type="circle" start="3" reversed><li>a</li></ul>'));
        self::assertSame('<ul><li>a</li></ul>', $sanitizer->sanitize('<ul type="a"><li>a</li></ul>'));
    }

    public function testLinksKeepOnlyTheNewTabTargetAndItsRelTokensInOrder(): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame('<a href="/x" rel="noopener noreferrer">x</a>', $sanitizer->sanitize('<a href="/x" target="_self" rel="NoReferrer nofollow  noopener external">x</a>'));
        self::assertSame('<a href="/x">x</a>', $sanitizer->sanitize('<a href="/x" target="frame" rel="nofollow">x</a>'));
        self::assertSame('<a href="/x">x</a>', $sanitizer->sanitize('<a href="/x" id="anchor" name="anchor">x</a>'), 'no anchors');
    }

    public function testLanguageMarksKeepOnlyTheEditorsLanguages(): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame('<p><span lang="he" dir="rtl">a</span></p>', $sanitizer->sanitize('<p><span lang="he" dir="rtl">a</span></p>'));
        foreach (['en-US', 'xx', 'EN', ' en', ''] as $lang) {
            self::assertSame('<p><span dir="ltr">a</span></p>', $sanitizer->sanitize('<p><span lang="' . $lang . '" dir="ltr">a</span></p>'), $lang);
        }
        self::assertSame('<p><span lang="en">a</span></p>', $sanitizer->sanitize('<p><span lang="en" dir="auto">a</span></p>'));
    }

    public function testEveryInputIsADisabledCheckbox(): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame('<label><input type="checkbox" checked="checked" disabled="disabled" />a</label>', $sanitizer->sanitize('<label><input type="checkbox" checked name="x" value="1" onclick="x()">a</label>'));
        self::assertSame('<label><input disabled="disabled" type="checkbox" />a</label>', $sanitizer->sanitize('<label><input disabled="" type="checkbox">a</label>'), 'boolean attributes take their name');
        self::assertSame('<p>ab</p>', $sanitizer->sanitize('<p>a<input type="text" value="x"><input><input type="password" checked>b</p>'), 'other inputs go');
        self::assertSame('<p>ab</p>', $sanitizer->sanitize('<p>a<input type="hidden" name="token" value="x"><input type="image" src="/a.png" formaction="https://evil.example/">b</p>'), 'hidden and image inputs too');
        self::assertSame('<p><input type="checkbox" disabled="disabled" />a</p>', $sanitizer->sanitize('<p><input type="checkbox" formaction="https://evil.example/" form="f">a</p>'), 'a checkbox keeps no form attributes');
        self::assertSame('<p>&lt;input type&#61;&#34;text&#34; /&gt;</p>', $sanitizer->sanitize('<p>&lt;input type="text" /&gt;</p>'), 'text is left alone');
        self::assertSame('<p></p>', $sanitizer->sanitize('<p><button>b</button><select><option>o</option></select><textarea>t</textarea></p><form><input type="checkbox"></form>'));
    }

    public function testStyleValuesAreCheckedAfterTheParserDecodedTheirEntities(): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame('<p><span style="color: red">a</span></p>', $sanitizer->sanitize('<p><span style="&#99;olor:red">a</span></p>'));
        self::assertSame('<p><span>a</span></p>', $sanitizer->sanitize('<p><span style="color:red;background-color:u&#114;l(https://evil.example/)">a</span></p>'));
        self::assertSame('<p><span>a</span></p>', $sanitizer->sanitize('<p><span style="color:red&#59;background-color:&#117;rl(x)">a</span></p>'));
        self::assertSame('<p><span>a</span></p>', $sanitizer->sanitize('<p><span style="color:red&#92;3b">a</span></p>'));
        self::assertSame('<p><span>a</span></p>', $sanitizer->sanitize('<p><span style="color:red&#x21;important">a</span></p>'));
        self::assertSame('<figure><img src="/a.png" /></figure>', $sanitizer->sanitize('<figure style="float:left;margin-left:0"><img src="/a.png" style="border:1px solid red;position:fixed"></figure>'));
    }

    public function testDropsDangerousAndUnknownElements(): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame(
            '<p>Hi</p><p>A <span class="x" style="color: red">red</span></p>',
            $sanitizer->sanitize('<p>Hi<script>alert(1)</script></p><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe><p>A <span class="x" style="color:red">red</span></p><style>p{}</style>'),
        );
        self::assertSame('<a>x</a>', $sanitizer->sanitize('<a href="javascript:alert(1)">x</a>'));
        self::assertSame('<img alt="a" />', $sanitizer->sanitize('<img src="javascript:alert(1)" alt="a">'));
    }

    public function testIframesFromEditorPreviewsAreNeverStored(): void
    {
        // CKEditor's previewsInData output (not used: it carries inline styles) loses its players and data attributes.
        $stored = new RichTextSanitizer(null)->sanitize(
            '<figure class="media"><div data-oembed-url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"><div>'
            . '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" style="width: 100%; aspect-ratio: 16 / 9;" allowfullscreen=""></iframe>'
            . '</div></div></figure>',
        );

        self::assertSame('<figure class="media"><div><div></div></div></figure>', $stored);
    }

    public function testClassesNeedAValidSyntaxAndAreCapped(): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame('<p class="a _b c-1 D">x</p>', $sanitizer->sanitize('<p class=" a  _b 1x c-1 a D -e ü" >x</p>'));
        self::assertSame('<p>x</p>', $sanitizer->sanitize('<p class="1x &lt;b&gt; &quot;">x</p>'), 'no valid token drops the attribute');
        self::assertSame('<p class="c1 c2 c3 c4 c5 c6 c7 c8">x</p>', $sanitizer->sanitize('<p class="c1 c2 c3 c4 c5 c6 c7 c8 c9 c10">x</p>'));
        self::assertSame('<p class="' . str_repeat('a', 64) . '">x</p>', $sanitizer->sanitize('<p class="' . str_repeat('a', 64) . ' ' . str_repeat('b', 65) . '">x</p>'));
    }

    public function testASetOfClassesKeepsOnlyThoseClasses(): void
    {
        $sanitizer = new RichTextSanitizer(['lead', 'image']);

        self::assertSame(
            '<p class="lead">a</p><p>b</p><figure class="image"><img src="/uploads/a.png" /></figure>',
            $sanitizer->sanitize('<p class="lead pasted">a</p><p class="MsoNormal">b</p><figure class="image old-theme"><img src="/uploads/a.png"></figure>'),
        );
        self::assertSame('<p>a</p>', new RichTextSanitizer([])->sanitize('<p class="lead">a</p>'));
    }

    public function testASetOfFontFamiliesKeepsOnlyThoseFamilies(): void
    {
        $sanitizer = new RichTextSanitizer(null, ['Georgia, serif']);

        self::assertSame(
            '<p><span style="font-family: Georgia, serif">a</span><span style="color: red">b</span></p>',
            $sanitizer->sanitize('<p><span style="font-family:Georgia,serif">a</span><span style="color:red;font-family:Arial">b</span></p>'),
        );
    }

    public function testTheMinimalProfileKeepsParagraphsBoldItalicAndSpans(): void
    {
        $sanitizer = new RichTextSanitizer(['text-big', 'text-small'], null, minimal: true);

        self::assertSame(
            '<p><strong>a</strong><em>b</em><span class="text-big" style="color: red">c</span><span>d</span><br />e</p>',
            $sanitizer->sanitize('<p class="lead"><strong>a</strong><em>b</em><span class="text-big marker" style="color:red;background-color:blue" lang="en">c</span><span class="text-huge" style="font-family:Georgia">d</span><br>e</p>'),
        );
        self::assertSame(
            'H <p>a b c</p> 1',
            $sanitizer->sanitize('<h3>H</h3><p><a href="/x">a</a> <mark class="marker-yellow">b</mark> <code>c</code></p><ol><li>1</li></ol><form><input type="checkbox"></form><style>p{}</style>'),
        );
        self::assertSame('<p>x</p>', $sanitizer->sanitize('<p>x</p><figure class="table"><table><tbody><tr><td><img src="/a.png"></td></tr></tbody></table></figure>'), 'tables keep their text only');
        $stored = '<p><strong>a</strong> <span class="text-small" style="color: #123456">b</span></p>';
        self::assertSame($stored, $sanitizer->sanitize($stored), 'idempotent');
        self::assertSame($stored, new RichTextSanitizer(null, null)->sanitize($stored), 'a minimal value is a valid full one');
    }

    /**
     * Unwrapped blocks leave a space behind, so the words of list items, table cells or headings posted as HTML
     * (without the editor) do not run together; inline elements are unwrapped without one.
     */
    public function testTheMinimalProfileKeepsTheWordsOfUnwrappedBlocksApart(): void
    {
        $sanitizer = new RichTextSanitizer(null, null, minimal: true);

        self::assertSame('one two', $sanitizer->sanitize('<ul><li>one</li><li>two</li></ul>'));
        self::assertSame('one two three', $sanitizer->sanitize("<ol>\n  <li>one</li>\n  <li>two<ul><li>three</li></ul></li>\n</ol>"));
        self::assertSame('a b c', $sanitizer->sanitize('<table><thead><tr><th>a</th></tr></thead><tbody><tr><td>b</td><td>c</td></tr></tbody></table>'));
        self::assertSame('Title <p>Text</p> <p>Quote</p>', $sanitizer->sanitize('<h2>Title</h2><p>Text</p><blockquote><p>Quote</p></blockquote>'));
        self::assertSame('<p>a</p> <p>b</p>', $sanitizer->sanitize('<p>a</p><hr><div><p>b</p></div>'));
        self::assertSame('<p>bold and <strong>strong</strong></p>', $sanitizer->sanitize('<p><b>bo</b>ld and <strong>strong</strong></p>'), 'inline elements add no space');
        foreach (['one two', 'Title <p>Text</p> <p>Quote</p>', '<p>a</p> <p>b</p>'] as $stored) {
            self::assertSame($stored, $sanitizer->sanitize($stored), 'idempotent');
        }
    }

    public function testVideoUrlsAreStoredCanonicalOrDropped(): void
    {
        $sanitizer = new RichTextSanitizer(null);

        self::assertSame('<oembed url="https://www.youtube.com/watch?v&#61;dQw4w9WgXcQ"></oembed>', $sanitizer->sanitize('<oembed url="https://youtu.be/dQw4w9WgXcQ?t=42"></oembed>'));
        self::assertSame('<oembed url="https://vimeo.com/76979871"></oembed>', $sanitizer->sanitize('<oembed url="https://player.vimeo.com/video/76979871"></oembed>'));
        self::assertSame('<oembed url="https://www.youtube.com/watch?v&#61;dQw4w9WgXcQ"></oembed>', $sanitizer->sanitize('<oembed url="www.www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed>'), 'a link the editor previews is kept');
        foreach (['https://vimeo.com/76979871/abcdef', 'https://evil.example/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)', ''] as $url) {
            self::assertSame('<oembed></oembed>', $sanitizer->sanitize('<oembed url="' . $url . '"></oembed>'), $url);
        }
    }
}
