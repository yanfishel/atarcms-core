<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Markdown\RichTextMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RichTextMarkdownTest extends TestCase
{
    private const string BASE = 'https://example.com';

    #[DataProvider('conversions')]
    public function testConvertsTheSanitizedElementsToMarkdown(string $html, string $markdown): void
    {
        self::assertSame($markdown, RichTextMarkdown::convert($html, self::BASE));
    }

    public function testHeadingsCanSitLevelsDeeperUpToSix(): void
    {
        self::assertSame("## One\n\n### Two\n\n###### Five\n\n###### Six", RichTextMarkdown::convert('<h1>One</h1><h2>Two</h2><h5>Five</h5><h6>Six</h6>', self::BASE, 1));
    }

    public function testHeadingsKeepATrailingHashSequence(): void
    {
        self::assertSame('# Hello \#', RichTextMarkdown::heading(1, 'Hello #'));
        self::assertSame('### C#', RichTextMarkdown::heading(3, 'C#'));
        self::assertSame('###### Deep', RichTextMarkdown::heading(9, 'Deep'));
    }

    public function testEscapedTextReadsBack(): void
    {
        self::assertSame('\[a\] \*b\* snake_case \_c\_', RichTextMarkdown::escape('[a] *b* snake_case _c_'));
        self::assertSame("\\# a\n  \\- b\n3\\) c", RichTextMarkdown::escapeLineStarts("# a\n  - b\n3) c"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conversions(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'only whitespace' => ["<p> \n </p><p></p>", ''];
        yield 'paragraphs' => ['<p>One</p><p>Two</p>', "One\n\nTwo"];
        yield 'whitespace collapses' => ["<p>  One \n   two\tthree </p>", 'One two three'];
        yield 'unicode text' => ['<p>Привет, שלום</p>', 'Привет, שלום'];
        yield 'bare text' => ['Just text', 'Just text'];
        yield 'line break' => ['<p>One<br>two<br><br>three</p>', "One\\\ntwo\\\nthree"];
        yield 'divs unwrap' => ['<div><p>One</p><div>Two</div></div>', "One\n\nTwo"];

        // Headings, with marks inside.
        yield 'h1' => ['<h1>Title</h1>', '# Title'];
        yield 'h2 with marks' => ['<h2>Hello <strong>world</strong></h2>', '## Hello **world**'];
        yield 'h3' => ['<h3>Three</h3>', '### Three'];
        yield 'h4' => ['<h4>Four</h4>', '#### Four'];
        yield 'h5' => ['<h5>Five</h5>', '##### Five'];
        yield 'h6' => ['<h6>Six</h6>', '###### Six'];
        yield 'heading with a break is one line' => ['<h2>One<br>two</h2>', '## One two'];
        yield 'empty heading' => ['<h2> </h2><p>Text</p>', 'Text'];

        // Inline marks.
        yield 'strong and b' => ['<p><strong>a</strong> <b>b</b></p>', '**a** **b**'];
        yield 'em and i' => ['<p><em>a</em> <i>b</i></p>', '*a* *b*'];
        yield 's and del' => ['<p><s>a</s> <del>b</del></p>', '~~a~~ ~~b~~'];
        yield 'nested marks' => ['<p><strong>bold <em>both</em></strong></p>', '**bold *both***'];
        yield 'spaces move outside the marks' => ['<p>a<strong> b </strong>c</p>', 'a **b** c'];
        yield 'multibyte text in marks' => ['<p><strong>Мир</strong> <em>שלום</em><br><s>über</s></p>', "**Мир** *שלום*\\\n~~über~~"];
        yield 'marks around a break' => ['<p><strong>a<br></strong>b</p>', "**a**\\\nb"];
        yield 'empty marks go' => ['<p>a<strong></strong><em> </em>b</p>', 'a b'];
        yield 'marks without markdown keep their text' => [
            '<p><u>u</u> <sub>2</sub> <sup>3</sup> <mark class="marker-yellow">m</mark> <span style="color:red">s</span></p>',
            'u 2 3 m s',
        ];
        yield 'inline code' => ['<p>Run <code>composer install</code></p>', 'Run `composer install`'];
        yield 'inline code with backticks' => ['<p><code>a`b</code></p>', '``a`b``'];
        yield 'inline code starting with a backtick' => ['<p><code>`a</code></p>', '`` `a ``'];

        // Code blocks.
        yield 'pre' => ["<pre>line 1\n  line 2</pre>", "```\nline 1\n  line 2\n```"];
        yield 'pre with a language' => [
            '<pre><code class="language-php">echo 1 * 2;</code></pre>',
            "```php\necho 1 * 2;\n```",
        ];
        yield 'pre with a fence inside' => ["<pre>```\nx\n```</pre>", "````\n```\nx\n```\n````"];

        // Quotes and rules.
        yield 'blockquote' => ['<blockquote><p>One</p><p>Two</p></blockquote>', "> One\n>\n> Two"];
        yield 'blockquote with a list' => ['<blockquote><ul><li>a</li><li>b</li></ul></blockquote>', "> - a\n> - b"];
        yield 'nested blockquotes' => ['<blockquote><blockquote><p>Deep</p></blockquote></blockquote>', '> > Deep'];
        yield 'hr' => ['<p>a</p><hr><p>b</p>', "a\n\n---\n\nb"];

        // Lists.
        yield 'ul' => ['<ul><li>One</li><li>Two</li></ul>', "- One\n- Two"];
        yield 'ol' => ['<ol><li>One</li><li>Two</li></ol>', "1. One\n2. Two"];
        yield 'ol with a start' => ['<ol start="4"><li>Four</li><li>Five</li></ol>', "4. Four\n5. Five"];
        yield 'reversed ol' => ['<ol reversed start="3"><li>Three</li><li>Two</li></ol>', "3. Three\n2. Two"];
        yield 'list in a list' => [
            '<ul><li>One<ul><li>One A</li><li>One B</li></ul></li><li>Two</li></ul>',
            "- One\n  - One A\n  - One B\n- Two",
        ];
        yield 'ul in ol' => [
            '<ol><li>One<ul><li>a</li></ul></li><li>Two<ol><li>b</li></ol></li></ol>',
            "1. One\n   - a\n2. Two\n   1. b",
        ];
        yield 'paragraphs in items' => [
            '<ul><li><p>One</p><p>More</p></li><li><p>Two</p></li></ul>',
            "- One\n\n  More\n\n- Two",
        ];
        yield 'marks in items' => ['<ul><li><strong>Bold</strong> item</li></ul>', '- **Bold** item'];
        yield 'empty item' => ['<ul><li></li><li>Two</li></ul>', "-\n- Two"];
        yield 'to-do list' => [
            '<ul class="todo-list"><li><label class="todo-list__label"><input type="checkbox" disabled checked><span class="todo-list__label__description">Done</span></label></li>'
            . '<li><label class="todo-list__label"><input type="checkbox" disabled><span class="todo-list__label__description">Open</span></label></li></ul>',
            "- [x] Done\n- [ ] Open",
        ];

        // Links.
        yield 'link' => ['<p>See <a href="https://atar.dev/docs">the docs</a>.</p>', 'See [the docs](https://atar.dev/docs).'];
        yield 'link with a title' => ['<p><a href="https://atar.dev" title="Say &quot;hi&quot;">Atar</a></p>', '[Atar](https://atar.dev "Say \"hi\"")'];
        yield 'relative link becomes absolute' => ['<p><a href="/about">About</a></p>', '[About](https://example.com/about)'];
        yield 'anchor and relative links stay' => ['<p><a href="#top">Top</a> <a href="page">Page</a></p>', '[Top](#top) [Page](page)'];
        yield 'protocol-relative stays' => ['<p><a href="//cdn.example.org/x">X</a></p>', '[X](//cdn.example.org/x)'];
        yield 'mailto' => ['<p><a href="mailto:me@example.com">Write</a></p>', '[Write](mailto:me@example.com)'];
        yield 'brackets in the link text' => ['<p><a href="https://a.b/">[1] note</a></p>', '[\[1\] note](https://a.b/)'];
        yield 'parentheses and spaces in the url' => ['<p><a href="https://a.b/x (1)">X</a></p>', '[X](https://a.b/x%20%281%29)'];
        yield 'empty link text shows the url' => ['<p><a href="https://a.b/"></a></p>', '[https://a.b/](https://a.b/)'];
        yield 'link without href keeps its text' => ['<p><a>Plain</a></p>', 'Plain'];
        yield 'marks in a link' => ['<p><a href="https://a.b/"><strong>Bold</strong> link</a></p>', '[**Bold** link](https://a.b/)'];

        // Images and figures.
        yield 'inline image' => ['<p>An <img src="/uploads/a.jpg" alt="apple"> here</p>', 'An ![apple](https://example.com/uploads/a.jpg) here'];
        yield 'image without alt' => ['<p><img src="https://cdn.example.org/b.png"></p>', '![](https://cdn.example.org/b.png)'];
        yield 'image with brackets in alt' => ['<p><img src="/a.jpg" alt="a [b]"></p>', '![a \[b\]](https://example.com/a.jpg)'];
        yield 'figure with a caption' => [
            '<figure class="image"><img src="/uploads/a.jpg" alt="Apple"><figcaption>A <em>red</em> apple</figcaption></figure>',
            "![Apple](https://example.com/uploads/a.jpg)\n\nA *red* apple",
        ];
        yield 'linked image' => ['<figure class="image"><a href="/big"><img src="/a.jpg" alt="A"></a></figure>', '[![A](https://example.com/a.jpg)](https://example.com/big)'];

        // Tables stay HTML.
        yield 'table' => [
            '<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table>',
            '<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table>',
        ];
        yield 'table in a figure with a caption' => [
            '<p>Before</p><figure class="table"><table><thead><tr><th colspan="2">H</th></tr></thead><tbody><tr><td>a &amp; b</td><td>c</td></tr></tbody></table><figcaption>Cap</figcaption></figure>',
            "Before\n\n<table><thead><tr><th colspan=\"2\">H</th></tr></thead><tbody><tr><td>a &amp; b</td><td>c</td></tr></tbody></table>\n\nCap",
        ];
        yield 'blank lines leave a table' => [
            "<table><tbody>\n\n<tr><td>a</td></tr>\n\n</tbody></table>",
            "<table><tbody>\n<tr><td>a</td></tr>\n</tbody></table>",
        ];

        // Videos.
        yield 'stored video' => [
            '<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>',
            '[YouTube video](https://www.youtube.com/watch?v=dQw4w9WgXcQ)',
        ];
        yield 'vimeo video' => ['<figure class="media"><oembed url="https://vimeo.com/76979871"></oembed></figure>', '[Vimeo video](https://vimeo.com/76979871)'];
        yield 'rendered player' => [
            '<figure class="media"><iframe class="rich-text-video" src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" title="YouTube"></iframe></figure>',
            '[YouTube video](https://www.youtube.com/watch?v=dQw4w9WgXcQ)',
        ];
        yield 'unsupported video goes' => ['<p>a</p><figure class="media"><oembed url="https://example.org/v"></oembed></figure>', 'a'];

        // Review fixes.
        yield 'an exclamation mark before a link' => ['<p>Wow!<a href="/x">link</a> and Hi!</p>', 'Wow\![link](https://example.com/x) and Hi!'];
        yield 'a backslash and an exclamation mark before a link' => ['<p>a\!<a href="/x">link</a></p>', 'a\\\\\![link](https://example.com/x)'];
        yield 'an exclamation mark before an image stays' => ['<p>Wow! <img src="/a.jpg" alt="a"></p>', 'Wow! ![a](https://example.com/a.jpg)'];
        yield 'urls in a table become absolute' => [
            '<table><tbody><tr><td><a href="/x">x</a><img src="/i.png" alt=""><a href="https://a.b/">y</a><a href="//c.d/">z</a></td></tr></tbody></table>',
            '<table><tbody><tr><td><a href="https://example.com/x">x</a><img src="https://example.com/i.png" alt=""><a href="https://a.b/">y</a><a href="//c.d/">z</a></td></tr></tbody></table>',
        ];
        yield 'adjacent marks merge' => ['<p><strong>a</strong><strong>b</strong> <em>c</em><i>d</i><s>e</s><del>f</del></p>', '**ab** *cd*~~ef~~'];
        yield 'marks with text between stay apart' => ['<p><strong>a</strong>-<strong>b</strong></p>', '**a**-**b**'];
        yield 'a heading ending in hashes keeps them' => ['<h2>Learn C#</h2><h2>Tag #</h2><h2>Many ##</h2>', "## Learn C#\n\n## Tag \\#\n\n## Many \\##"];

        // Escaping.
        yield 'markdown characters in text' => ['<p>2 * 3 = 6, a_b, _x_, `c`, [d], &lt;e&gt;, \\f, ~~g~~</p>', '2 \* 3 = 6, a_b, \_x\_, \`c\`, \[d\], \<e>, \\\\f, \~\~g\~\~'];
        yield 'block markers at a line start' => [
            '<p># not a heading</p><p>1. not a list</p><p>- not an item</p><p>+ nor this</p><p>&gt; nor a quote</p><p>=== no</p>',
            "\\# not a heading\n\n1\\. not a list\n\n\\- not an item\n\n\\+ nor this\n\n\\> nor a quote\n\n\\=== no",
        ];
        yield 'markers after a break' => ['<p>a<br># b</p>', "a\\\n\\# b"];
        yield 'markers mid-line stay' => ['<p>a # b - c 1. d</p>', 'a # b - c 1. d'];
    }
}
