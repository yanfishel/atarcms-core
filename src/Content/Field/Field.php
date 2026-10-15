<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use AtarCms\Kernel\ConfigException;

/**
 * A field of a collection, declared in config/collections.php: Field::text('summary')->required()->max(300).
 */
final class Field
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,47}$/';

    /** Entry columns; a field may not reuse one of these names. */
    public const array RESERVED = [
        'id', 'collection', 'locale', 'translation_group', 'slug', 'title', 'status', 'published_at', 'parent_id',
        'position', 'author_id', 'created_at', 'updated_at', 'meta_title', 'meta_description', 'share_image', 'preset', 'data',
    ];

    /** The entry form tabs a field can be shown on; the other tabs hold the entry's own inputs only. */
    public const array TABS = ['content', 'media', 'settings'];

    public private(set) string $label;

    public private(set) bool $required = false;

    public private(set) bool $indexed = false;

    public private(set) int|float|null $min = null;

    /** Maximum length, count or value, depending on the type. */
    public private(set) int|float|null $max = null;

    /** @var array<int|string, string> value => label (a translation key or plain text) */
    public private(set) array $options = [];

    /** The collection a relation field points to. */
    public private(set) ?string $target = null;

    /** The taxonomy a tags field stores terms of. */
    public private(set) ?string $taxonomy = null;

    /** The entry form tab the field is shown on, one of TABS. */
    public private(set) string $tab = 'content';

    /** A rich-text field with the minimal profile: bold, italic, font colour and size, paragraphs (see minimal()). */
    public private(set) bool $minimal = false;

    /** A rich-text field edited in a taller editor (see tall()). */
    public private(set) bool $tall = false;

    /** A hint shown under the input in the entry form (a translation key or plain text); see help(). */
    public private(set) ?string $help = null;

    private function __construct(
        public readonly string $name,
        public readonly FieldType $type,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new ConfigException(sprintf(
                'Invalid field name "%s"; use lowercase letters, digits and "_", starting with a letter (at most 48 characters)',
                $name,
            ));
        }
        if (in_array($name, self::RESERVED, true)) {
            throw new ConfigException(sprintf('Field name "%s" is reserved for an entry column', $name));
        }
        $this->label = $name;
    }

    public static function make(string $name, FieldType $type): self
    {
        return new self($name, $type);
    }

    public static function text(string $name): self
    {
        return new self($name, new TextType());
    }

    public static function textarea(string $name): self
    {
        return new self($name, new TextareaType());
    }

    public static function number(string $name): self
    {
        return new self($name, new NumberType());
    }

    public static function boolean(string $name): self
    {
        return new self($name, new BooleanType());
    }

    public static function date(string $name): self
    {
        return new self($name, new DateType());
    }

    public static function datetime(string $name): self
    {
        return new self($name, new DateTimeType());
    }

    /**
     * @param array<mixed> $options value => label (a translation key or plain text)
     */
    public static function select(string $name, array $options): self
    {
        if ($options === []) {
            throw new ConfigException(sprintf('Select field "%s" needs at least one option', $name));
        }
        $field = new self($name, new SelectType());
        foreach ($options as $value => $label) {
            if (!is_string($label)) {
                throw new ConfigException(sprintf('Option "%s" of select field "%s" needs a string label', $value, $name));
            }
            $field->options[(string) $value] = $label;
        }

        return $field;
    }

    /**
     * HTML edited in the admin's rich-text editor; ->max() limits the length of its visible text, ->minimal() makes
     * it a short formatted text (see minimal()).
     */
    public static function richText(string $name): self
    {
        return new self($name, new RichTextType());
    }

    /**
     * Ids of entries of $collection; ->max() limits how many can be chosen.
     */
    public static function relation(string $name, string $collection): self
    {
        $field = new self($name, new RelationType());
        $field->target = $collection;

        return $field;
    }

    /**
     * Terms of a flat taxonomy (by default the one named like the field), edited in the admin's tag box (posted as
     * comma-separated names, the plain input without JavaScript);
     * ->required() and ->max() limit the number of tags. The terms live in entry_terms, not in the entry's data:
     * themes read them with $this->terms($entry, 'tags').
     */
    public static function tags(string $name, ?string $taxonomy = null): self
    {
        $field = new self($name, new TagsType());
        $field->taxonomy = $taxonomy ?? $name;

        return $field;
    }

    public static function youtube(string $name): self
    {
        return new self($name, new YouTubeType());
    }

    /**
     * One image of the media library (its id); themes load it with mediaFor() and print it with img().
     */
    public static function image(string $name): self
    {
        return new self($name, new ImageType());
    }

    /**
     * Images of the media library in a chosen order (their ids); ->max() limits how many can be chosen.
     */
    public static function gallery(string $name): self
    {
        return new self($name, new GalleryType());
    }

    /**
     * Slides of a hero slider: each an image of the media library with an optional title, subtitle, link and button
     * text; ->max() limits how many (default 10). The entry form shows the field on a tab of its own, named by its
     * label; themes read a list of Slide objects and load the images with mediaFor().
     */
    public static function slides(string $name): self
    {
        return new self($name, new SlidesType());
    }

    /**
     * One file of the media library of any allowed type, e.g. a PDF (its id).
     */
    public static function file(string $name): self
    {
        return new self($name, new FileType());
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function required(): self
    {
        $this->required = true;

        return $this;
    }

    /**
     * Adds a generated column with an index so entries can be filtered and sorted by this field.
     * Apply it with "bin/atar collections:sync" and "bin/atar migrate".
     */
    public function indexed(): self
    {
        $this->indexed = true;

        return $this;
    }

    /**
     * The entry form tab of the field: "content" (the default, the wide column under the title), "media" (next to
     * it, for photos, videos and files) or "settings" (the narrow column, under the status and publication date).
     */
    public function tab(string $tab): self
    {
        if (!in_array($tab, self::TABS, true)) {
            throw new ConfigException(sprintf('Field "%s": unknown tab "%s"; use %s', $this->name, $tab, implode(', ', self::TABS)));
        }
        $this->tab = $tab;

        return $this;
    }

    /**
     * A rich-text field with the minimal profile: the editor offers only bold, italic, font colour and font size, and
     * Enter starts a new paragraph; the stored HTML keeps only p, br, strong, em and span (the font size classes and a
     * color style), whatever is posted. For summaries and teasers.
     */
    public function minimal(): self
    {
        if (!$this->type instanceof RichTextType) {
            throw new ConfigException(sprintf('Field "%s": only a rich-text field can be minimal', $this->name));
        }
        $this->minimal = true;

        return $this;
    }

    /**
     * A hint shown under the field's input in the entry form, in muted text (a translation key or plain text); a
     * rich-text field shows it on the left of the editor's word count.
     */
    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    /**
     * A rich-text field edited in a taller editor (the text of a whole page); without JavaScript its textarea has
     * more rows.
     */
    public function tall(): self
    {
        if (!$this->type instanceof RichTextType) {
            throw new ConfigException(sprintf('Field "%s": only a rich-text field can be tall', $this->name));
        }
        $this->tall = true;

        return $this;
    }

    public function min(int|float $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function max(int|float $max): self
    {
        $this->max = $max;

        return $this;
    }
}
