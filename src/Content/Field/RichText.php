<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * Rich text for themes. The HTML was sanitized when the entry was saved; print it raw with `$value->html`.
 * The class is deliberately not Stringable, so `$this->e()` refuses it instead of double-escaping.
 */
final readonly class RichText
{
    /** The start of an opening or closing tag of a block element or <br> (of the elements RichTextType allows). */
    private const string BLOCK_TAG = '~<(?=/?(?:p|div|br|h[1-6]|blockquote|pre|hr|ul|ol|li|figure|figcaption|table|thead|tbody|tfoot|tr|th|td|caption)\b)~i';

    public function __construct(public string $html) {}

    public function isEmpty(): bool
    {
        return $this->html === '';
    }

    /**
     * Plain text without tags, e.g. for excerpts and the search index; escape it like any other text. Block elements
     * and line breaks separate words ("<p>a</p><p>b</p>" reads "a b"), and runs of whitespace become one space.
     */
    public function text(): string
    {
        $spaced = (string) preg_replace(self::BLOCK_TAG, ' <', $this->html);
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\x{A0}]+/u', ' ', $text));
    }
}
