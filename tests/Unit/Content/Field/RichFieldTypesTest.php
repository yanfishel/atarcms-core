<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\FieldError;
use AtarCms\Content\Field\RichText;
use AtarCms\Content\Field\YouTubeVideo;
use AtarCms\Kernel\ConfigException;
use AtarCms\Tests\Support\Cast;
use PHPUnit\Framework\TestCase;

final class RichFieldTypesTest extends TestCase
{
    public function testRichTextKeepsSafeMarkup(): void
    {
        $field = Field::richText('body');
        $html = '<p>Hello <strong>world</strong> and <a href="https://example.com/x">a link</a></p>';

        self::assertSame($html, $field->type->normalize($html, $field));
        self::assertSame('richText', $field->type->name());
        self::assertNull($field->type->columnType($field));
    }

    public function testRichTextStripsDangerousMarkup(): void
    {
        $field = Field::richText('body');

        $stored = Cast::string($field->type->normalize(
            '<p onclick="steal()">Hi<script>alert(1)</script></p>'
            . '<a href="javascript:alert(1)">x</a>'
            . '<img src="/a.png" onerror="alert(1)">'
            . '<iframe src="https://evil.example"></iframe>',
            $field,
        ));

        self::assertStringContainsString('Hi', $stored);
        self::assertStringNotContainsString('script', $stored);
        self::assertStringNotContainsString('onclick', $stored);
        self::assertStringNotContainsString('onerror', $stored);
        self::assertStringNotContainsString('javascript', $stored);
        self::assertStringNotContainsString('iframe', $stored);
    }

    public function testRichTextStoresLongArticlesWhole(): void
    {
        $field = Field::richText('body');
        $html = '<p>' . str_repeat('a', 30000) . '</p>';

        self::assertSame($html, $field->type->normalize($html, $field));
    }

    public function testRichTextEmptyAndLimits(): void
    {
        $field = Field::richText('body');

        self::assertNull($field->type->normalize('', $field));
        self::assertNull($field->type->normalize('<p> &nbsp; </p>', $field));
        self::assertNull($field->type->normalize('<script>alert(1)</script>', $field));
        self::assertNull($field->type->normalize(null, $field));
        self::assertError('validation.field.too_long', ['max' => 10], Field::richText('intro')->max(10), '<p>12345 <strong>67890</strong></p>');
        self::assertError('validation.field.too_long', ['max' => 200000], $field, '<p>' . str_repeat('a', 200000) . '</p>');
        self::assertError('validation.field.invalid', [], $field, ['<p>x</p>']);
        self::assertSame('<hr />', $field->type->normalize('<hr>', $field));
    }

    /**
     * ->max() on rich text counts the visible text (RichText::text()), as on a textarea, not the markup.
     */
    public function testMaxLimitsTheVisibleText(): void
    {
        $field = Field::richText('intro')->max(10);

        self::assertSame(
            '<p><strong>12345</strong></p><p><span style="color: red">6789</span></p>',
            $field->type->normalize('<p><strong>12345</strong></p><p><span style="color:red">6789</span></p>', $field),
            'ten characters: nine digits and the space between the paragraphs',
        );
        self::assertError('validation.field.too_long', ['max' => 10], $field, '<p>12345678901</p>');
        self::assertError('validation.field.too_long', ['max' => 3], Field::richText('intro')->minimal()->max(3), '<p>ab</p><p>cd</p>');
        self::assertError('validation.field.too_long', ['max' => 6], Field::richText('intro')->minimal()->max(6), '<ul><li>one</li><li>two</li></ul>');
        $minimal = Field::richText('intro')->minimal()->max(7);
        self::assertSame('one two', $minimal->type->normalize('<ul><li>one</li><li>two</li></ul>', $minimal), 'seven characters: the words stay apart');
    }

    public function testAMinimalFieldKeepsOnlyParagraphsBoldItalicColourAndSize(): void
    {
        $field = Field::richText('summary')->minimal();

        self::assertTrue($field->minimal);
        self::assertFalse(Field::richText('body')->minimal);
        self::assertSame(
            '<p><strong>Bold</strong> <em>italic</em> <span class="text-big" style="color: #e64d4d">red</span><br />line</p><p>Two</p>',
            $field->type->normalize('<p><strong>Bold</strong> <em>italic</em> <span class="text-big" style="color:#e64d4d;font-family:Georgia">red</span><br>line</p><p>Two</p>', $field),
        );
        self::assertSame(
            'Title <p>A link and bold</p> item <p>x</p>',
            $field->type->normalize('<h2 class="lead">Title</h2><p class="lead" style="color:red">A <a href="https://example.com">link</a> and <b>bold</b></p><ul><li>item</li></ul><figure class="image"><img src="/uploads/a.png" alt="A"></figure><p>x<script>alert(1)</script></p><iframe src="https://example.com"></iframe>', $field),
            'other elements of the full profile are unwrapped (the editor puts loose text into a paragraph), the rest dropped, also without the editor',
        );
        self::assertSame('<p><span>a</span></p>', $field->type->normalize('<p><span style="background-color:red;font-size:40px">a</span></p>', $field), 'only color');
        self::assertNull($field->type->normalize('<figure class="media"><oembed url="https://youtu.be/dQw4w9WgXcQ"></oembed></figure><hr>', $field));
        $stored = '<p><strong>Bold</strong> <span class="text-small" style="color: #123">small</span></p>';
        self::assertSame($stored, $field->type->normalize($stored, $field), 'idempotent');
    }

    public function testOnlyARichTextFieldCanBeMinimal(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Field "summary": only a rich-text field can be minimal');

        Field::textarea('summary')->minimal();
    }

    public function testRichTextIsIdempotentAndReadsAsRichText(): void
    {
        $field = Field::richText('body');
        $stored = $field->type->normalize('<p>A &amp; B<br>c</p><ul><li>one</li></ul>', $field);

        self::assertSame($stored, $field->type->normalize($stored, $field));
        $read = $field->type->read('<p>A &amp; B</p>', $field);
        self::assertInstanceOf(RichText::class, $read);
        self::assertSame('<p>A &amp; B</p>', $read->html);
        self::assertSame('A & B', $read->text());
        $empty = $field->type->read(null, $field);
        self::assertInstanceOf(RichText::class, $empty);
        self::assertTrue($empty->isEmpty());
        // Reflection: RichText is not Stringable by its declaration, which PHPStan already knows statically.
        self::assertFalse((new \ReflectionClass(RichText::class))->implementsInterface(\Stringable::class));
    }

    public function testRichTextAllowsOnlyTheWhitelist(): void
    {
        $field = Field::richText('body');

        $stored = Cast::string($field->type->normalize(
            '<img src="/a.png" srcset="https://evil.example/x.png 1x, javascript:alert(1) 2x" alt="a">',
            $field,
        ));
        self::assertStringNotContainsString('srcset', $stored);
        self::assertStringNotContainsString('javascript', $stored);
        self::assertSame('<img src="/a.png" alt="a" />', $stored);

        $plaintext = $field->type->normalize('<p>before</p><plaintext>abc<p>after</p>', $field);
        self::assertStringNotContainsString('plaintext', Cast::string($plaintext));
        self::assertSame($plaintext, $field->type->normalize(Cast::string($plaintext), $field));

        $xmp = $field->type->normalize('<p>before</p><xmp><script>x</script></xmp><p>after</p>', $field);
        self::assertStringNotContainsString('xmp', Cast::string($xmp));
        self::assertStringNotContainsString('script', Cast::string($xmp));
        self::assertSame('<p>before</p><p>after</p>', $xmp);
        self::assertSame($xmp, $field->type->normalize((string) $xmp, $field));

        self::assertSame('<a>x</a>', $field->type->normalize('<a href="data:text/html,evil">x</a>', $field));
        self::assertSame('<a>x</a>', $field->type->normalize('<a href="javascript&colon;alert(1)">x</a>', $field));
        self::assertSame('<a>x</a>', $field->type->normalize('<a href="&#106;avascript:alert(1)">x</a>', $field));

        self::assertSame('<p>Hi</p>', $field->type->normalize('<p id="x" style="color:red">Hi</p>', $field));

        self::assertNull($field->type->normalize(
            '<button onclick="x()">Click</button><template><p>t</p></template>',
            $field,
        ));

        $tableHtml = '<table><thead><tr><th colspan="2">H</th></tr></thead>'
            . '<tbody><tr><td rowspan="1">D</td></tr></tbody></table>';
        self::assertSame($tableHtml, $field->type->normalize($tableHtml, $field));

        self::assertSame(
            '<img src="/a.png" alt="a" width="10" height="20" title="t" />',
            $field->type->normalize('<img src="/a.png" alt="a" width="10" height="20" title="t">', $field),
        );
        self::assertSame('<h2>Title</h2>', $field->type->normalize('<h2>Title</h2>', $field));
        self::assertSame('<blockquote>Q</blockquote>', $field->type->normalize('<blockquote>Q</blockquote>', $field));
    }

    public function testRelation(): void
    {
        $field = Field::relation('related', 'articles')->max(3);

        self::assertSame('articles', $field->target);
        self::assertSame([1, 2, 3], $field->type->normalize([1, '2', ' 3 '], $field));
        self::assertSame([4, 5], $field->type->normalize('4, 5', $field));
        self::assertSame([7], $field->type->normalize(7, $field));
        self::assertSame([2], $field->type->normalize([2, 2], $field));
        self::assertNull($field->type->normalize([], $field));
        self::assertNull($field->type->normalize('', $field));
        self::assertError('validation.field.invalid', [], $field, [0]);
        self::assertError('validation.field.invalid', [], $field, ['x']);
        self::assertError('validation.field.invalid', [], $field, [1.5]);
        self::assertError('validation.field.too_many', ['max' => 3], $field, [1, 2, 3, 4]);
        self::assertSame([1, 2], $field->type->read([1, 2], $field));
        self::assertSame([3], $field->type->read(['a', 3], $field));
        self::assertSame([2], $field->type->read([-1, 2, 0], $field));
        self::assertSame([], $field->type->read(null, $field));
        self::assertNull($field->type->columnType($field));
    }

    public function testKeepsTheEditorsImagesWithTheirAspectRatio(): void
    {
        $field = Field::richText('body');
        $path = '2026/09/' . str_repeat('a', 32) . '.jpg';

        self::assertSame(
            '<figure class="image"><img style="aspect-ratio: 3/2" src="/uploads/' . $path . '" alt="A cat" width="1200" height="800" /><figcaption>Our cat</figcaption></figure>',
            $field->type->normalize('<figure class="image"><img style="aspect-ratio:3/2;" src="/uploads/' . $path . '" alt="A cat" width="1200" height="800"><figcaption>Our cat</figcaption></figure>', $field),
        );
        self::assertSame(
            '<figure class="image"><img width="10" height="10" /></figure>',
            $field->type->normalize('<figure class="image"><img src="blob:https://localhost/1" width="10" height="10"></figure>', $field),
            'an unfinished upload keeps no blob: address',
        );
    }

    public function testAVideoAloneIsContentAndReadsAsAPlayer(): void
    {
        $field = Field::richText('body');

        $stored = $field->type->normalize('<figure class="media"><oembed url="https://youtu.be/dQw4w9WgXcQ"></oembed></figure>', $field);

        self::assertSame('<figure class="media"><oembed url="https://www.youtube.com/watch?v&#61;dQw4w9WgXcQ"></oembed></figure>', $stored);
        self::assertSame($stored, $field->type->normalize($stored, $field));
        self::assertNull($field->type->normalize('<figure class="media"><oembed url="https://evil.example/x"></oembed></figure>', $field), 'an unsupported video is nothing');
        $read = $field->type->read($stored, $field);
        self::assertInstanceOf(RichText::class, $read);
        self::assertStringStartsWith('<figure class="media"><iframe class="rich-text-video" src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" title="YouTube"', $read->html);
        self::assertSame('', $read->text(), 'a player has no text for excerpts and search');
    }

    public function testYouTube(): void
    {
        $field = Field::youtube('video');

        foreach ([
            'dQw4w9WgXcQ',
            ' https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10 ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ] as $input) {
            self::assertSame('dQw4w9WgXcQ', $field->type->normalize($input, $field), $input);
        }
        self::assertNull($field->type->normalize('', $field));
        foreach (['https://evil.example/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)', 'short', 'https://www.youtube.com/watch?v=bad'] as $bad) {
            self::assertError('validation.field.youtube', [], $field, $bad);
        }
        $video = $field->type->read('dQw4w9WgXcQ', $field);
        self::assertInstanceOf(YouTubeVideo::class, $video);
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $video->embedUrl());
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $video->watchUrl());
        self::assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $video->thumbnailUrl(), 'the 480×360 cover every video has');
        self::assertNull($field->type->read(null, $field));
        self::assertSame('dQw4w9WgXcQ', YouTubeVideo::parse(' https://youtu.be/dQw4w9WgXcQ ')?->id, 'what normalize() accepts');
        self::assertSame('dQw4w9WgXcQ', YouTubeVideo::parse('dQw4w9WgXcQ')?->id);
        self::assertNull(YouTubeVideo::parse('https://vimeo.com/123'));
        self::assertNull(YouTubeVideo::parse(''));
    }

    /**
     * @param array<string, int|float|string> $params
     */
    private static function assertError(string $key, array $params, Field $field, mixed $input): void
    {
        try {
            $field->type->normalize($input, $field);
        } catch (FieldError $e) {
            self::assertSame($key, $e->key);
            self::assertSame($params, $e->params);

            return;
        }
        self::fail(sprintf('Expected %s for %s', $key, var_export($input, true)));
    }
}
