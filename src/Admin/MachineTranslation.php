<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\RichTextType;
use AtarCms\Content\Field\SlidesType;
use AtarCms\Content\Field\TextareaType;
use AtarCms\Content\Field\TextType;
use AtarCms\I18n\Locales;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Machine translation in the browser (Plan 38): the copy's form translates its texts from the source language with
 * the browser's on-device Translator API (translate.js). The server translates nothing; it only carries the choice:
 * the machine button of a "Copy for translation" form posts "machine" (chosen()), the translate actions then open the
 * copy's form with ?machine={source}&start=1 (query()), and that form renders the hidden panel
 * (partials/machine-translation.php) for a valid ?machine= (requested()). start=1 means the editor chose machine
 * translation: the script starts at once when the model is already on the device; without it (the top bar's switch,
 * which creates a single's copy by itself) the panel waits for a click.
 */
final readonly class MachineTranslation
{
    public const string FIELD = 'machine';

    /**
     * @param list<string> $fields the names of the inputs and textareas to translate (rich text included)
     * @param list<string> $slides the slides fields whose title, subtitle and button text are translated
     * @param bool $slug the address input is emptied, so a draft's first save makes it from the translated title
     */
    public function __construct(
        public string $from,
        public string $to,
        public bool $start,
        public array $fields = [],
        public array $slides = [],
        public bool $slug = false,
    ) {}

    /**
     * The machine button was pressed (the translate actions' marker).
     */
    public static function chosen(ServerRequestInterface $request): bool
    {
        $body = $request->getParsedBody();

        return is_array($body) && ($body[self::FIELD] ?? null) === '1';
    }

    /**
     * The query of the copy's form after the translate action ($start: the editor chose machine translation).
     *
     * @return array<string, string>
     */
    public static function query(string $from, bool $start = true): array
    {
        return $start ? [self::FIELD => $from, 'start' => '1'] : [self::FIELD => $from];
    }

    /**
     * The panel of the form of an item in $to: ?machine= names another site language; anything else is nothing.
     */
    public static function requested(ServerRequestInterface $request, string $to, Locales $locales): ?self
    {
        $query = $request->getQueryParams();
        $from = $query[self::FIELD] ?? null;
        if (!is_string($from) || $from === $to || !$locales->isSupported($from) || !$locales->isSupported($to)) {
            return null;
        }

        return new self($from, $to, ($query['start'] ?? null) === '1');
    }

    /**
     * The entry form's texts: the built-in title and search-engine texts the form shows (a preset page's copy is titled
     * by its label in its own language already), the text, textarea and rich-text fields, the slides fields. A draft's
     * address input is emptied (not a single's or a preset page's, which are fixed), so its save makes it from the
     * translated title.
     */
    public function forEntry(Collection $collection, Entry $entry): self
    {
        $preset = $entry->isPreset();
        $fields = $collection->plain ? [] : [...($preset ? [] : ['title']), ...($collection->route !== null ? ['meta_title', 'meta_description'] : [])];
        $slides = [];
        foreach ($collection->fields as $name => $field) {
            if ($field->type instanceof TextType || $field->type instanceof TextareaType || $field->type instanceof RichTextType) {
                $fields[] = 'fields[' . $name . ']';
            } elseif ($field->type instanceof SlidesType) {
                $slides[] = $name;
            }
        }

        $slug = $entry->status === EntryStatus::Draft && !$collection->single && !$preset;

        return new self($this->from, $this->to, $this->start, $fields, $slides, $slug);
    }

    /**
     * The term or section form's texts: the name (TermService::createTranslation() copies no search-engine texts); a
     * term keeps a blank address's slug.
     */
    public function forTerm(): self
    {
        return new self($this->from, $this->to, $this->start, ['name']);
    }
}
