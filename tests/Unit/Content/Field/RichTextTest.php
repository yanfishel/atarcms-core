<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\RichText;
use PHPUnit\Framework\TestCase;

final class RichTextTest extends TestCase
{
    public function testBlockElementsAreSeparatedInThePlainText(): void
    {
        self::assertSame('a b', (new RichText('<p>a</p><p>b</p>'))->text());
        self::assertSame(
            'Title one two x y cell cell quote',
            (new RichText('<h2>Title</h2><p>one<br>two</p><ul><li>x</li><li>y</li></ul><table><tr><td>cell</td><td>cell</td></tr></table><blockquote>quote</blockquote>'))->text(),
        );
    }

    public function testInlineElementsDoNotSplitWords(): void
    {
        self::assertSame('word and link', (new RichText('<p>wo<strong>rd</strong> and <a href="/x">link</a></p>'))->text());
    }

    public function testEntitiesAreDecodedAndWhitespaceCollapsed(): void
    {
        self::assertSame('A & B "c"', (new RichText("<p>A &amp; B\n\n &nbsp; &quot;c&quot;</p>"))->text());
        self::assertSame('', (new RichText(''))->text());
    }

    /**
     * The search index and excerpts read the text of the editor's features: code blocks, to-do items, language marks
     * and styled spans, never the markup of their checkboxes, styles or columns.
     */
    public function testTheEditorsFeaturesReadAsPlainText(): void
    {
        $html = '<pre><code class="language-php">echo 1;</code></pre>'
            . '<ul class="todo-list"><li><label class="todo-list__label"><input type="checkbox" checked="checked" disabled="disabled" /><span class="todo-list__label__description">Buy milk</span></label></li></ul>'
            . '<p><span style="color: #e64d4d">red</span> and <span lang="ar" dir="rtl">مرحبا</span></p>'
            . '<figure class="table" style="width: 80%"><table class="ck-table-resized"><colgroup><col style="width: 50%" /></colgroup><tbody><tr><td>cell</td></tr></tbody></table></figure>';

        self::assertSame('echo 1; Buy milk red and مرحبا cell', (new RichText($html))->text());
    }
}
