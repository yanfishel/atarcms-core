<?php

declare(strict_types=1);

namespace AtarCms\Content\Markdown;

use AtarCms\Content\Field\VideoUrl;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Stored rich text (the closed element set of RichTextSanitizer) as markdown, for the markdown versions of entry pages
 * (EntryMarkdown). A walk over the parsed HTML, no markdown library:
 * - blocks: p and figcaption become paragraphs, h1–h6 ATX headings on one line (optionally some levels deeper, at
 *   most "######"), blockquote "> " lines, pre a fenced
 *   code block (its "language-*" class names the language; the fence outgrows any backtick run inside), hr "---",
 *   ul/ol lists ("- ", "N. " from start, counting down when reversed; nested lists and the lines of an item indented by
 *   its marker; an item with more than one block makes the list loose), div and figure only group their children;
 * - tables stay the sanitized HTML (valid in markdown; blank lines inside are dropped so the HTML block stays whole);
 * - inline: strong/b "**", em/i "*", s/del "~~" (spaces at their edges moved outside, empty ones dropped), code in
 *   backticks, a "[text](url "title")" (no text: the URL), img "![alt](src)", br a backslash line break (a single
 *   space in headings), a to-do checkbox "[x] " or "[ ] ", a video (stored <oembed>, or a rendered player) a link to
 *   its canonical page; u, sub, sup, mark, span and label keep only their text;
 * - URLs starting with one "/" are made absolute with $base; spaces and parentheses in URLs are percent-encoded;
 * - text: runs of whitespace become one space (not in pre and code), and \ * _ (not inside a word) ` [ ] < ~ are
 *   backslash-escaped, as is a # > - + = or "N." starting a line, so the text reads back as it was written.
 */
final class RichTextMarkdown
{
    private const array BLOCKS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'hr', 'ul', 'ol', 'li', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    ];

    /** A loose list item: more than one of these among its children. */
    private const array ITEM_BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'figure', 'table'];

    /** Stands for a <br> while a paragraph is assembled. */
    private const string BREAK = "\u{E000}";

    private function __construct(
        private readonly HTMLDocument $document,
        private readonly string $base,
        private readonly int $headingShift,
    ) {}

    /**
     * @param string $html stored (sanitized) rich text
     * @param string $base the site's absolute URL (app.url), for root-relative links and images
     * @param int $headingShift levels added to every heading (at most "######"): rich text under a heading of its own
     */
    public static function convert(string $html, string $base, int $headingShift = 0): string
    {
        if (trim($html) === '') {
            return '';
        }
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;
        if ($body === null) {
            return '';
        }

        return trim(implode("\n\n", new self($document, rtrim($base, '/'), max(0, $headingShift))->blocks($body)));
    }

    /**
     * Plain text as markdown text: the characters markdown would read as syntax are backslash-escaped (see the class
     * comment; a line start is escaped by escapeLineStarts()).
     */
    public static function escape(string $text): string
    {
        $text = (string) preg_replace('/([\\\\*`\[\]<~])/u', '\\\\$1', $text);

        return (string) preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\\\_', $text);
    }

    /**
     * Plain text (a title, a name, a label) as markdown text on one line: whitespace runs collapse to one space, then
     * escape().
     */
    public static function line(string $text): string
    {
        return self::escape(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * $markdown with a "#", ">", "-", "+", "=" or "N." / "N)" at the start of any of its lines escaped: plain text
     * there would otherwise start a heading, a quote, a list or a setext underline.
     */
    public static function escapeLineStarts(string $markdown): string
    {
        $markdown = (string) preg_replace('/^([ \t]*)([#>+=-])/mu', '$1\\\\$2', $markdown);

        return (string) preg_replace('/^([ \t]*)(\d+)([.)])/mu', '$1$2\\\\$3', $markdown);
    }

    /**
     * A markdown link; $url is percent-encoded where markdown would end it (spaces, parentheses).
     */
    public static function link(string $text, string $url, ?string $title = null): string
    {
        $titlePart = $title === null || $title === '' ? '' : ' "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $title) . '"';

        return '[' . $text . '](' . self::destination($url) . $titlePart . ')';
    }

    private static function destination(string $url): string
    {
        return str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $url);
    }

    /**
     * The markdown blocks of $parent's children: runs of inline content become paragraphs.
     *
     * @return list<string>
     */
    private function blocks(Node $parent): array
    {
        $blocks = [];
        $run = [];
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof Element || !in_array($child->localName, self::BLOCKS, true)) {
                $run[] = $child;

                continue;
            }
            $blocks = [...$blocks, ...$this->paragraph($this->run($run)), ...$this->block($child)];
            $run = [];
        }

        return [...$blocks, ...$this->paragraph($this->run($run))];
    }

    /**
     * A heading line; a "#" run closing the text ("Tag #") is escaped, or markdown would drop it as a closing sequence.
     */
    public static function heading(int $level, string $text): string
    {
        return str_repeat('#', max(1, min(6, $level))) . ' ' . (string) preg_replace('/(^|\s)(#+)$/u', '$1\\\\$2', $text);
    }

    /**
     * @return list<string>
     */
    private function block(Element $element): array
    {
        $name = $element->localName;

        return match (true) {
            $name === 'p', $name === 'figcaption' => $this->paragraph($this->children($element)),
            (bool) preg_match('/^h([1-6])$/', $name, $level) => $this->headingOf((int) $level[1], $element),
            $name === 'blockquote' => $this->quote($element),
            $name === 'pre' => [$this->code($element)],
            $name === 'hr' => ['---'],
            $name === 'ul', $name === 'ol' => $this->listOf($element),
            $name === 'li' => $this->items([$element], false, 1, false),
            $name === 'table' => [$this->table($element)],
            default => $this->blocks($element),
        };
    }

    /**
     * @return list<string> the paragraph, or none when $inline is blank
     */
    private function paragraph(string $inline): array
    {
        $lines = [];
        foreach (explode(self::BREAK, $inline) as $line) {
            $line = trim((string) preg_replace('/ {2,}/', ' ', $line));
            if ($line !== '') {
                $lines[] = self::escapeLineStarts($line);
            }
        }

        return $lines === [] ? [] : [implode("\\\n", $lines)];
    }

    /**
     * @return list<string>
     */
    private function headingOf(int $level, Element $element): array
    {
        $text = trim((string) preg_replace('/ {2,}/', ' ', str_replace(self::BREAK, ' ', $this->children($element))));

        return $text === '' ? [] : [self::heading($level + $this->headingShift, $text)];
    }

    /**
     * @return list<string>
     */
    private function quote(Element $element): array
    {
        $inner = implode("\n\n", $this->blocks($element));
        if ($inner === '') {
            return [];
        }

        return [implode("\n", array_map(static fn(string $line): string => $line === '' ? '>' : '> ' . $line, explode("\n", $inner)))];
    }

    private function code(Element $element): string
    {
        $language = self::language($element);
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element && $child->localName === 'code') {
                $language ??= self::language($child);
            }
        }
        $text = rtrim((string) $element->textContent, "\n");
        $fence = str_repeat('`', max(3, self::longestRun($text) + 1));

        return $fence . ($language ?? '') . "\n" . $text . "\n" . $fence;
    }

    private static function language(Element $element): ?string
    {
        return preg_match('/(?:^|\s)language-([A-Za-z0-9_+#-]+)/', (string) $element->getAttribute('class'), $match) === 1 ? $match[1] : null;
    }

    private static function longestRun(string $text): int
    {
        preg_match_all('/`+/', $text, $runs);

        return $runs[0] === [] ? 0 : max(array_map(strlen(...), $runs[0]));
    }

    /**
     * @return list<string>
     */
    private function listOf(Element $list): array
    {
        $items = [];
        foreach ($list->childNodes as $child) {
            if ($child instanceof Element && $child->localName === 'li') {
                $items[] = $child;
            }
        }
        if ($items === []) {
            return [];
        }
        $ordered = $list->localName === 'ol';
        $reversed = $ordered && $list->hasAttribute('reversed');
        $start = $list->getAttribute('start');
        $first = $start !== null && preg_match('/^-?\d+$/', $start) === 1 ? (int) $start : ($reversed ? count($items) : 1);

        return $this->items($items, $ordered, $first, $reversed);
    }

    /**
     * @param list<Element> $items
     *
     * @return list<string>
     */
    private function items(array $items, bool $ordered, int $number, bool $reversed): array
    {
        $rendered = [];
        $loose = false;
        foreach ($items as $item) {
            $marker = $ordered ? $number . '. ' : '- ';
            $number += $reversed ? -1 : 1;
            $blocks = 0;
            foreach ($item->childNodes as $child) {
                if ($child instanceof Element && in_array($child->localName, self::ITEM_BLOCKS, true)) {
                    $blocks++;
                }
            }
            $itemLoose = $blocks > 1;
            if ($itemLoose) {
                $loose = true;
            }
            $body = implode($itemLoose ? "\n\n" : "\n", $this->blocks($item));
            $indent = str_repeat(' ', strlen($marker));
            $lines = explode("\n", $body);
            $text = rtrim($marker . array_shift($lines));
            foreach ($lines as $line) {
                $text .= "\n" . ($line === '' ? '' : $indent . $line);
            }
            $rendered[] = $text;
        }

        return [implode($loose ? "\n\n" : "\n", $rendered)];
    }

    private function table(Element $table): string
    {
        foreach ($table->querySelectorAll('[href], [src]') as $element) {
            foreach (['href', 'src'] as $attribute) {
                $url = $element->getAttribute($attribute);
                if ($url !== null) {
                    $element->setAttribute($attribute, $this->absolute(trim($url)));
                }
            }
        }

        return (string) preg_replace('/\n\s*\n/', "\n", trim($this->document->saveHtml($table)));
    }

    /**
     * The inline markdown of $element's children.
     */
    private function children(Node $element): string
    {
        return $this->run(iterator_to_array($element->childNodes, false));
    }

    /**
     * The inline markdown of sibling nodes. Neighbouring marks of one kind ("<b>a</b><strong>b</strong>") become one
     * ("**ab**", not "**a****b**"), and a "!" that a link would follow is escaped (else "!" + "[…](…)" reads as an
     * image).
     *
     * @param list<Node> $nodes
     */
    private function run(array $nodes): string
    {
        $text = '';
        $count = count($nodes);
        for ($index = 0; $index < $count; $index++) {
            $node = $nodes[$index];
            $marker = self::marker($node);
            if ($marker === null) {
                $piece = $this->inline($node);
            } else {
                $inner = $this->children($node);
                while ($index + 1 < $count && self::marker($nodes[$index + 1]) === $marker) {
                    $inner .= $this->children($nodes[++$index]);
                }
                $piece = self::wrap($marker, $inner);
            }
            // A "!" before a link would read as an image; an even run of backslashes before it leaves it unescaped.
            if (str_starts_with($piece, '[') && preg_match('/(?<!\\\\)(?:\\\\\\\\)*!$/', $text) === 1) {
                $text = substr($text, 0, -1) . '\\!';
            }
            $text .= $piece;
        }

        return $text;
    }

    /**
     * The markdown mark of an inline mark element, or null.
     */
    private static function marker(Node $node): ?string
    {
        if (!$node instanceof Element) {
            return null;
        }

        return match ($node->localName) {
            'strong', 'b' => '**',
            'em', 'i' => '*',
            's', 'del' => '~~',
            default => null,
        };
    }

    private function inline(Node $node): string
    {
        if ($node instanceof Text) {
            return self::escape((string) preg_replace('/[\t\n\r\f ]+/', ' ', $node->data));
        }
        if (!$node instanceof Element) {
            return '';
        }
        $marker = self::marker($node);
        if ($marker !== null) {
            return self::wrap($marker, $this->children($node));
        }

        return match ($node->localName) {
            'code' => self::inlineCode((string) $node->textContent),
            'br' => self::BREAK,
            'a' => $this->anchor($node),
            'img' => $this->image($node),
            'input' => $node->getAttribute('type') === 'checkbox' ? ($node->hasAttribute('checked') ? '[x] ' : '[ ] ') : '',
            'oembed' => self::video($node->getAttribute('url')),
            'iframe' => self::video($node->getAttribute('src')),
            default => in_array($node->localName, self::BLOCKS, true) ? ' ' . $this->children($node) . ' ' : $this->children($node),
        };
    }

    /**
     * $inner between $marker pairs, the whitespace at its edges moved outside (markdown needs the marks to touch the
     * text); blank $inner stays as it is.
     */
    private static function wrap(string $marker, string $inner): string
    {
        // A regular expression, not trim(): the break mark is a multibyte character, and trim() works on bytes.
        if (preg_match('/^([ \x{E000}]*)(.*?)([ \x{E000}]*)$/su', $inner, $parts) !== 1 || $parts[2] === '') {
            return $inner;
        }

        return $parts[1] . $marker . $parts[2] . $marker . $parts[3];
    }

    private static function inlineCode(string $text): string
    {
        $text = (string) preg_replace('/[\t\n\r\f ]+/', ' ', $text);
        if (trim($text) === '') {
            return '';
        }
        $fence = str_repeat('`', self::longestRun($text) + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';

        return $fence . $pad . $text . $pad . $fence;
    }

    private function anchor(Element $anchor): string
    {
        $text = trim($this->children($anchor), ' ');
        $href = $anchor->getAttribute('href');
        if ($href === null || trim($href) === '') {
            return $text;
        }
        $url = $this->absolute(trim($href));

        return self::link($text === '' ? self::escape($url) : $text, $url, $anchor->getAttribute('title'));
    }

    private function image(Element $image): string
    {
        $src = $image->getAttribute('src');
        if ($src === null || trim($src) === '') {
            return '';
        }

        return '!' . self::link(self::escape((string) $image->getAttribute('alt')), $this->absolute(trim($src)), $image->getAttribute('title'));
    }

    private static function video(?string $url): string
    {
        $video = $url === null ? null : VideoUrl::parse($url);
        if ($video === null) {
            return '';
        }

        return self::link(($video->provider === VideoUrl::YOUTUBE ? 'YouTube' : 'Vimeo') . ' video', $video->canonical());
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $this->base . $url : $url;
    }
}
