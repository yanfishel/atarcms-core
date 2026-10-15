<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The allow-list of stored rich text: the elements the admin editor writes, a few attributes per element, link and
 * image schemes http, https, mailto (links) and relative URLs, and videos as <oembed url> of a supported video
 * (canonicalized by OembedUrlSanitizer). Inline styles only on the elements whose features need them, with the
 * properties and values of StyleAttributeSanitizer; fixed-value attributes (list styles, link targets, language
 * marks, to-do checkboxes) through KeywordAttributeSanitizer. Every <input> left is a disabled checkbox. Everything
 * else goes: id, on*, data-*, iframe, script, form controls, ... The class policy decides which classes stay
 * (ClassAttributeSanitizer): any of valid syntax (null), or only a set; the font policy which font families stay.
 * The minimal profile (Field::minimal()) keeps only p, br, strong, em and span (its classes, and a color style);
 * other elements of the full allow-list are unwrapped (their text stays; a block leaves a space behind, so the words
 * of list items or table cells stay apart), the rest is dropped as always.
 * Sanitizing its own output returns it unchanged.
 */
final class RichTextSanitizer
{
    /** Elements that take only a class. */
    public const array CLASS_ELEMENTS = [
        'p', 'div', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'sub', 'sup',
        'blockquote', 'code', 'pre', 'hr', 'li', 'figcaption', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'mark',
        'label', 'colgroup',
    ];

    /** The elements of the full allow-list a minimal field unwraps: their text stays, the element goes. */
    public const array MINIMAL_UNWRAPPED = [
        'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'b', 'i', 'u', 's', 'del', 'sub', 'sup', 'blockquote', 'code', 'pre',
        'ul', 'ol', 'li', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'mark',
        'label', 'a',
    ];

    /** The start of an opening or closing tag of a block element that the minimal profile unwraps or drops (hr). */
    private const string MINIMAL_BLOCK_TAG = '~<(?=/?(?:div|h[1-6]|blockquote|pre|hr|ul|ol|li|figure|figcaption|table|thead|tbody|tfoot|tr|th|td|caption)\b)~i';

    private readonly HtmlSanitizer $sanitizer;

    /**
     * @param list<string>|null $classes the classes to keep; null keeps every class of valid syntax
     * @param list<string>|null $fontFamilies the font families to keep (normalized); null keeps every valid one
     * @param bool $minimal the minimal profile ($classes are then the span classes to keep, $fontFamilies is unused)
     */
    public function __construct(?array $classes, ?array $fontFamilies = null, private readonly bool $minimal = false)
    {
        $this->sanitizer = new HtmlSanitizer($minimal ? self::minimalConfig($classes) : self::fullConfig($classes, $fontFamilies));
    }

    public function sanitize(string $html): string
    {
        if ($this->minimal) {
            // A space before every tag of an unwrapped block keeps the words of its neighbours apart
            // ("<li>one</li><li>two</li>" becomes "one two"); the runs of whitespace this leaves become one space.
            $html = (string) preg_replace(self::MINIMAL_BLOCK_TAG, ' <', $html);
        }
        // An <input> whose type is not "checkbox" (refused or missing) would be a text box: it goes. The sanitizer
        // writes every attribute double-quoted and escaped, so its output can be matched this way.
        $clean = (string) preg_replace('~<input(?![^>]* type="checkbox")[^>]*/>~', '', $this->sanitizer->sanitize($html));

        return trim($this->minimal ? (string) preg_replace('/[ \t\r\n]{2,}/', ' ', $clean) : $clean);
    }

    /**
     * @param list<string>|null $classes
     */
    private static function minimalConfig(?array $classes): HtmlSanitizerConfig
    {
        $config = new HtmlSanitizerConfig()
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('span', ['class', 'style'])
            ->withAttributeSanitizer(new ClassAttributeSanitizer($classes))
            ->withAttributeSanitizer(new StyleAttributeSanitizer(null, ['span' => ['color']]))
            ->withMaxInputLength(-1);
        foreach (self::MINIMAL_UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }

        return $config->defaultAction(HtmlSanitizerAction::Drop);
    }

    /**
     * @param list<string>|null $classes
     * @param list<string>|null $fontFamilies
     */
    private static function fullConfig(?array $classes, ?array $fontFamilies): HtmlSanitizerConfig
    {
        $config = new HtmlSanitizerConfig();
        foreach (self::CLASS_ELEMENTS as $element) {
            $config = $config->allowElement($element, ['class']);
        }
        // An explicit per-element allow-list, not allowSafeElements(): the latter also allows id/name and other
        // "safe" global attributes on every element (DOM clobbering) and its elements include ones like
        // <plaintext>/<xmp>/<button>/<template> the admin editor never produces.
        return $config
            ->allowElement('ol', ['class', 'type', 'start', 'reversed'])
            ->allowElement('ul', ['class', 'type'])
            ->allowElement('figure', ['class', 'style'])
            ->allowElement('table', ['class', 'style'])
            ->allowElement('th', ['colspan', 'rowspan', 'class', 'style'])
            ->allowElement('td', ['colspan', 'rowspan', 'class', 'style'])
            ->allowElement('col', ['style'])
            ->allowElement('span', ['class', 'style', 'lang', 'dir'])
            ->allowElement('a', ['href', 'title', 'class', 'target', 'rel'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'class', 'style'])
            ->allowElement('input', ['type', 'checked', 'disabled'])
            // A to-do checkbox on a public page never works as a form control.
            ->forceAttribute('input', 'disabled', 'disabled')
            ->allowElement('oembed', ['url'])
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            ->withAttributeSanitizer(new ClassAttributeSanitizer($classes))
            ->withAttributeSanitizer(new OembedUrlSanitizer())
            ->withAttributeSanitizer(new StyleAttributeSanitizer($fontFamilies))
            ->withAttributeSanitizer(new KeywordAttributeSanitizer())
            // The input length is limited by Text::assertLength(); -1 turns off the sanitizer's own silent
            // truncation (20 000 characters by default), so long articles are stored whole.
            ->withMaxInputLength(-1);
    }
}
