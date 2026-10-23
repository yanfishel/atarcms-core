<?php

declare(strict_types=1);

namespace AtarCms\Content\Markdown;

use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryMedia;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\Field\BooleanType;
use AtarCms\Content\Field\DateTimeType;
use AtarCms\Content\Field\DateType;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\FileType;
use AtarCms\Content\Field\GalleryType;
use AtarCms\Content\Field\ImageType;
use AtarCms\Content\Field\NumberType;
use AtarCms\Content\Field\RelationType;
use AtarCms\Content\Field\RichTextType;
use AtarCms\Content\Field\SelectType;
use AtarCms\Content\Field\Slide;
use AtarCms\Content\Field\SlidesType;
use AtarCms\Content\Field\TagsType;
use AtarCms\Content\Field\TextareaType;
use AtarCms\Content\Field\YouTubeType;
use AtarCms\Content\Field\YouTubeVideo;
use AtarCms\Content\PublicContent;
use AtarCms\Content\StructuredData\StructuredData;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermUrls;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\SiteTime;
use AtarCms\Media\Media;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaUrls;
use DateTimeImmutable;

/**
 * The markdown twin of a public entry page ("{address}.md", ContentController on a MarkdownPages::twin() route), for
 * AI agents (ListingMarkdown does archives and term pages). The document (document() puts YAML frontmatter above it):
 * - "# {title}", then "> {meta description}" when the entry has one;
 * - a list of facts: "Published: {date}" (the publication date in the site's time zone, SiteTime; not on a plain()
 *   single, which is always published and has no date of its own), "Language: {locale}" with links to the twins of
 *   its public translations, one line per taxonomy of Collection::$taxonomies the entry has terms of (linked to the
 *   term pages of a routed taxonomy), then one "{label}: {value}" line per scalar field;
 * - one "## {label}" section per block field, its rich text headings one level deeper (RichTextMarkdown).
 * Fields come in the entry form's order (by tab, then as configured); empty ones are left out. Scalar fields (one
 * line): text, number, select (its option's label), boolean (yes/no), date and datetime (wall-clock values, never
 * moved between zones), image and file (a link to the original), relation (links to the public, routed target
 * entries; others are left out), tags (the names), YouTube (a link to the video). Block fields (a section): rich text
 * (RichTextMarkdown), textarea (its lines, blank lines between paragraphs), gallery (a list of images) and slides (a
 * list of images with their texts and links). Labels are translated into the entry's language; the structure words
 * are English, like /llms.txt's.
 */
final readonly class EntryMarkdown
{
    public function __construct(
        private EntryUrls $urls,
        private TermRepository $terms,
        private TermUrls $termUrls,
        private Taxonomies $taxonomies,
        private PublicContent $content,
        private EntryMedia $entryMedia,
        private MediaRepository $media,
        private MediaUrls $mediaUrls,
        private Translator $translator,
        private SiteTime $siteTime,
        private Config $config,
    ) {}

    /**
     * The twin as served on its own (the ".md" address and the negotiated page): the YAML frontmatter (Frontmatter) —
     * title, the description (the entry's meta description, else $siteDescription, like og:description; left out when
     * both are empty), the canonical page URL, the language, the publication time (not on a plain() single, like the
     * facts line) and the modification time as last_updated (StructuredData's dateModified: the later of the last
     * save and the publication), both in UTC ISO 8601 — then render(). /llms-full.txt uses render() alone.
     *
     * @param array<int, Entry> $ancestors the entry's ancestors when already loaded
     * @param string $url the page's absolute canonical URL
     * @param string $siteDescription the site's description in the entry's language (MarkdownPages::siteDescription())
     */
    public function document(Entry $entry, array $ancestors, string $url, string $siteDescription): string
    {
        $fields = ['title' => $entry->title];
        $description = trim($entry->seo->metaDescription ?? '');
        $description = $description !== '' ? $description : trim($siteDescription);
        if ($description !== '') {
            $fields['description'] = $description;
        }
        $fields['url'] = $url;
        $fields['lang'] = $entry->locale;
        $modified = $entry->updatedAt;
        if ($entry->publishedAt !== null) {
            if (!$entry->collection->plain) {
                $fields['published'] = StructuredData::time($entry->publishedAt);
            }
            $modified = max($modified, $entry->publishedAt);
        }
        $fields['last_updated'] = StructuredData::time($modified);

        return Frontmatter::render($fields) . "\n" . $this->render($entry, $ancestors);
    }

    /**
     * The markdown without frontmatter.
     *
     * @param array<int, Entry> $ancestors the entry's ancestors when already loaded
     */
    public function render(Entry $entry, array $ancestors = []): string
    {
        $locale = $entry->locale;
        $collection = $entry->collection;
        $parts = [RichTextMarkdown::heading(1, self::line($entry->title))];
        $description = $entry->seo->metaDescription;
        if ($description !== null && trim($description) !== '') {
            // Escaped as a line start: "> 1. …" would be a list inside the quote.
            $parts[] = '> ' . RichTextMarkdown::escapeLineStarts(self::line($description));
        }

        $facts = [];
        if (!$collection->plain && $entry->publishedAt !== null) {
            $facts[] = '- Published: ' . $this->siteTime->readable($entry->publishedAt, $locale, false);
        }
        $facts[] = '- Language: ' . $locale . $this->translations($entry, $ancestors);
        $terms = $this->terms->forEntries([$entry->id])[$entry->id] ?? [];
        foreach ($collection->taxonomies as $name) {
            $line = $this->termLine($name, $terms, $locale);
            if ($line !== null) {
                $facts[] = $line;
            }
        }

        $media = $this->media->byIds($this->entryMedia->ids([$entry]));
        $sections = [];
        foreach (self::formOrder($collection) as $field) {
            $label = self::line($this->translator->label($locale, $field->label));
            if ($field->type instanceof TagsType) {
                $names = array_map(static fn(Term $term): string => self::line($term->name), array_values(array_filter($terms, static fn(Term $term): bool => $term->taxonomy === $field->taxonomy)));
                if ($names !== []) {
                    $facts[] = '- ' . $label . ': ' . implode(', ', $names);
                }

                continue;
            }
            $block = $this->block($entry, $field, $media);
            if ($block !== null) {
                if ($block !== '') {
                    $sections[] = RichTextMarkdown::heading(2, $label) . "\n\n" . $block;
                }

                continue;
            }
            $value = $this->scalar($entry, $field, $media);
            if ($value !== null && $value !== '') {
                $facts[] = '- ' . $label . ': ' . $value;
            }
        }

        return implode("\n\n", [...$parts, implode("\n", $facts), ...$sections]) . "\n";
    }

    /**
     * The fields as the entry form shows them: by tab (Field::TABS), then in configuration order.
     *
     * @return list<Field>
     */
    private static function formOrder(Collection $collection): array
    {
        $ordered = [];
        foreach (Field::TABS as $tab) {
            foreach ($collection->fields as $field) {
                if ($field->tab === $tab) {
                    $ordered[] = $field;
                }
            }
        }

        return $ordered;
    }

    /**
     * " (translations: [ru](…/ru/….md), …)" for the entry's public translations, '' without any.
     *
     * @param array<int, Entry> $ancestors
     */
    private function translations(Entry $entry, array $ancestors): string
    {
        $translations = $this->content->translations($entry);
        $base = $this->config->appUrl();
        $links = [];
        foreach ($this->urls->paths($translations, $ancestors, markdown: true) as $id => $path) {
            foreach ($translations as $translation) {
                if ($translation->id === $id) {
                    $links[] = RichTextMarkdown::link($translation->locale, $base . $path);
                }
            }
        }

        return $links === [] ? '' : ' (translations: ' . implode(', ', $links) . ')';
    }

    /**
     * @param list<Term> $terms the entry's terms of every taxonomy
     */
    private function termLine(string $name, array $terms, string $locale): ?string
    {
        $taxonomy = $this->taxonomies->find($name);
        $own = array_values(array_filter($terms, static fn(Term $term): bool => $term->taxonomy === $name));
        if ($taxonomy === null || $own === []) {
            return null;
        }
        $paths = $this->termUrls->paths($own);
        $base = $this->config->appUrl();
        $names = array_map(
            static fn(Term $term): string => isset($paths[$term->id]) ? RichTextMarkdown::link(self::line($term->name), $base . $paths[$term->id]) : self::line($term->name),
            $own,
        );

        return '- ' . self::line($this->translator->label($locale, $taxonomy->label)) . ': ' . implode(', ', $names);
    }

    /**
     * A block field's section body ('' when empty), or null for a scalar field.
     *
     * @param array<int, Media> $media
     */
    private function block(Entry $entry, Field $field, array $media): ?string
    {
        $type = $field->type;
        $stored = $entry->data[$field->name] ?? null;

        return match (true) {
            $type instanceof RichTextType => is_string($stored) ? RichTextMarkdown::convert($stored, $this->config->appUrl(), 1) : '',
            $type instanceof TextareaType => is_string($stored) ? self::paragraphs($stored) : '',
            $type instanceof GalleryType => implode("\n", array_map(
                fn(Media $image): string => '- ' . $this->image($image, $entry->locale),
                $this->found($type->read($stored, $field), $media),
            )),
            $type instanceof SlidesType => implode("\n", array_filter(array_map(
                fn(Slide $slide): string => $this->slide($slide, $media, $entry->locale),
                $type->read($stored, $field),
            ))),
            default => null,
        };
    }

    /**
     * A scalar field's value as markdown, '' or null when empty.
     *
     * @param array<int, Media> $media
     */
    private function scalar(Entry $entry, Field $field, array $media): ?string
    {
        $type = $field->type;
        $value = $entry->field($field->name);

        return match (true) {
            $type instanceof NumberType => is_int($value) || is_float($value) ? NumberType::format($value) : null,
            $type instanceof SelectType => is_string($value) ? self::line($this->translator->label($entry->locale, $field->options[$value] ?? $value)) : null,
            $type instanceof BooleanType => $value === true ? 'yes' : 'no',
            // Wall-clock values: shown as entered, never converted (SiteTime::readableWallDate() stays in UTC).
            $type instanceof DateType => $value instanceof DateTimeImmutable ? $this->siteTime->readableWallDate($value->format('Y-m-d'), $entry->locale) : null,
            $type instanceof DateTimeType => $value instanceof DateTimeImmutable
                ? $this->siteTime->readableWallDate($value->format('Y-m-d'), $entry->locale) . ', ' . $value->format('H:i')
                : null,
            $type instanceof ImageType => is_int($value) && isset($media[$value]) ? $this->image($media[$value], $entry->locale) : null,
            $type instanceof FileType => is_int($value) && isset($media[$value]) ? $this->file($media[$value]) : null,
            $type instanceof RelationType => $this->relations($entry, $field),
            $type instanceof YouTubeType => $value instanceof YouTubeVideo ? RichTextMarkdown::link('YouTube video', $value->watchUrl()) : null,
            default => is_string($value) ? self::line($value) : null,
        };
    }

    /**
     * Links to the public, routed entries a relation field points to, in its order (their translations in the entry's
     * language, ContentQuery::byIds()).
     */
    private function relations(Entry $entry, Field $field): ?string
    {
        $ids = $entry->field($field->name);
        if (!is_array($ids) || $ids === [] || $field->target === null) {
            return null;
        }
        $related = $this->content->query($field->target, $entry->locale)->byIds(array_values(array_filter($ids, is_int(...))));
        $base = $this->config->appUrl();
        $links = [];
        foreach ($this->urls->paths($related) as $id => $path) {
            foreach ($related as $target) {
                if ($target->id === $id) {
                    $links[] = RichTextMarkdown::link(self::line($target->title), $base . $path);
                }
            }
        }

        return $links === [] ? null : implode(', ', $links);
    }

    /**
     * @param list<int> $ids
     * @param array<int, Media> $media
     *
     * @return list<Media>
     */
    private function found(array $ids, array $media): array
    {
        return array_values(array_filter(array_map(static fn(int $id): ?Media => $media[$id] ?? null, $ids)));
    }

    /**
     * @param array<int, Media> $media
     */
    private function slide(Slide $slide, array $media, string $locale): string
    {
        $image = $media[$slide->imageId] ?? null;
        if ($image === null) {
            return '';
        }
        $parts = [$this->image($image, $locale)];
        $texts = array_values(array_filter([$slide->title, $slide->subtitle], static fn(?string $text): bool => $text !== null && trim($text) !== ''));
        if ($texts !== []) {
            $parts[] = implode(' — ', array_map(self::line(...), $texts));
        }
        if ($slide->url !== null && $slide->url !== '') {
            $url = $this->absolute($slide->url);
            $button = self::line($slide->button ?? '');
            $parts[] = RichTextMarkdown::link($button !== '' ? $button : RichTextMarkdown::escape($url), $url);
        }
        $video = $slide->videoId === null ? null : ($media[$slide->videoId] ?? null);
        if ($video !== null) {
            $parts[] = $this->file($video);
        }

        return '- ' . implode(' ', $parts);
    }

    private function image(Media $image, string $locale): string
    {
        return '!' . RichTextMarkdown::link(self::line($image->alt($locale) ?? ''), $this->mediaUrls->absolute($this->mediaUrls->original($image)));
    }

    private function file(Media $file): string
    {
        return RichTextMarkdown::link(self::line($file->originalName), $this->mediaUrls->absolute($this->mediaUrls->original($file)));
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $this->config->appUrl() . $url : $url;
    }

    /**
     * Plain text on one line, escaped.
     */
    private static function line(string $text): string
    {
        return RichTextMarkdown::line($text);
    }

    /**
     * A textarea's text: blank lines separate paragraphs, other line breaks stay as markdown line breaks.
     */
    private static function paragraphs(string $text): string
    {
        $paragraphs = [];
        foreach (preg_split('/\R\s*\R/u', trim($text)) ?: [] as $paragraph) {
            $lines = array_filter(array_map(
                static fn(string $line): string => RichTextMarkdown::escapeLineStarts(RichTextMarkdown::escape(trim((string) preg_replace('/[ \t]+/', ' ', $line)))),
                preg_split('/\R/u', $paragraph) ?: [],
            ), static fn(string $line): bool => $line !== '');
            if ($lines !== []) {
                $paragraphs[] = implode("\\\n", $lines);
            }
        }

        return implode("\n\n", $paragraphs);
    }
}
