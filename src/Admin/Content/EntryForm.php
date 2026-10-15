<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Admin\PositionParentFields;
use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\MediaFieldType;
use AtarCms\Content\Field\SlidesType;
use AtarCms\Content\Field\TagsType;
use AtarCms\Content\Term;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\SiteTime;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin entry form: parses a submission into an EntryInput and gives the values to (re-)render it.
 * Field values stay raw here; EntryService validates and normalizes them. Term pickers post
 * terms[{taxonomy}][] ids; a tags field posts comma-separated names in fields[{name}]. Media fields post media
 * ids (fields[{name}], comma-separated for galleries). published_at is shown and
 * entered in the site time zone (SiteTime) and travels as UTC. layout() places the inputs on the form's tabs.
 *
 * @phpstan-import-type EntryFormValues from EntryFormData
 */
final class EntryForm
{
    private const string DATETIME = 'Y-m-d\TH:i';

    private const array TEXT_KEYS = ['title', 'slug', 'status', 'published_at', 'parent_id', 'position', 'meta_title', 'meta_description'];

    /** The built-in input that shows each submitted key, when it is not the key itself (terms.* go to "terms"). */
    private const array ERROR_INPUTS = ['parent_id' => 'parent', 'position' => 'parent'];

    /** The tab of each built-in input, in form order. */
    private const array BUILTIN_TABS = [
        'title' => 'content',
        // The categories above the status (a user decision): the section or tags are what an editor picks most.
        'terms' => 'settings',
        'status' => 'settings',
        'published_at' => 'settings',
        'parent' => 'settings',
        // The social sharing preview first in its tab (a user decision of Plan 20).
        'share' => 'seo',
        'meta_title' => 'seo',
        'meta_description' => 'seo',
        'slug' => 'seo',
    ];

    /** Errors of the whole form, shown in their own alert above it (a stale form, an unsupported locale). */
    private const array FORM_ERRORS = ['entry', 'locale'];

    /** The current site wall-clock time in the publication date input's format (what choosing "Published" fills in). */
    public static function now(Clock $clock, SiteTime $time): string
    {
        return $time->format($clock->now(), self::DATETIME);
    }

    /**
     * @param Entry|null $entry the stored entry the form edits (null for a new one): a preset page, like a plain single,
     *                          has no status input and every save publishes it
     */
    public static function parse(ServerRequestInterface $request, Collection $collection, SiteTime $time, ?Entry $entry = null): EntryFormData
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $string = static function (string $key) use ($body): string {
            $value = $body[$key] ?? '';

            return is_string($value) ? $value : '';
        };

        $values = self::defaults();
        foreach (self::TEXT_KEYS as $key) {
            $values[$key] = $string($key);
        }
        $submitted = is_array($body['fields'] ?? null) ? $body['fields'] : [];
        foreach (array_keys($collection->fields) as $name) {
            if (array_key_exists($name, $submitted)) {
                $values['fields'][$name] = $submitted[$name];
            }
        }

        $errors = [];
        [$terms, $values['terms']] = self::terms($body, $collection, $values['fields'], $errors);
        // A plain single and a preset page have no status input: every save publishes them (so it needs the publish
        // permission).
        $status = $collection->plain || $entry?->isPreset() === true ? EntryStatus::Published : ($values['status'] === '' ? EntryStatus::Draft : EntryStatus::tryFrom($values['status']));
        if ($status === null) {
            $errors['status'] = 'validation.status.invalid';
        }
        $publishedAt = null;
        if (trim($values['published_at']) !== '') {
            $publishedAt = $time->parse(trim($values['published_at']), self::DATETIME);
            if ($publishedAt === null) {
                $errors['published_at'] = 'validation.field.datetime';
            }
        }
        $tree = PositionParentFields::parse($values['parent_id'], $values['position'], 'validation.parent.invalid');
        $parentId = $tree['parentId'];
        $position = $tree['position'];
        $errors = [...$errors, ...$tree['errors']];

        return new EntryFormData(
            new EntryInput(
                title: $values['title'],
                slug: $values['slug'],
                status: $status ?? EntryStatus::Draft,
                publishedAt: $publishedAt,
                parentId: $parentId,
                position: $position,
                fields: $values['fields'],
                metaTitle: $values['meta_title'],
                metaDescription: $values['meta_description'],
                terms: $terms,
            ),
            $values,
            $string('version'),
            $string('locale'),
            $errors,
        );
    }

    /**
     * @param list<Term> $terms the entry's terms (TermRepository::forEntries())
     *
     * @return EntryFormValues
     */
    public static function values(Entry $entry, SiteTime $time, array $terms = []): array
    {
        $collection = $entry->collection;
        $fields = $entry->data;
        $chosen = array_fill_keys($collection->taxonomies, []);
        $tags = [];
        foreach ($terms as $term) {
            if (isset($chosen[$term->taxonomy])) {
                $chosen[$term->taxonomy][] = (string) $term->id;
            }
            $field = $collection->tagsField($term->taxonomy);
            if ($field !== null) {
                $tags[$field->name][] = $term->name;
            }
        }
        foreach ($tags as $name => $names) {
            $fields[$name] = implode(', ', $names);
        }

        return [
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->status->value,
            'published_at' => $time->format($entry->publishedAt, self::DATETIME),
            'parent_id' => $entry->parentId === null ? '' : (string) $entry->parentId,
            'position' => (string) $entry->position,
            'meta_title' => $entry->seo->metaTitle ?? '',
            'meta_description' => $entry->seo->metaDescription ?? '',
            'fields' => $fields,
            'terms' => $chosen,
        ];
    }

    /**
     * The tabs of the form's two columns, after the RusDM editor. Main: "content" (the title, then the fields of the
     * default tab) and "media". Side: "settings" (status, publication date, parent page, term pickers, then the
     * fields of that tab) and "seo" (search engine texts, page address). The built-in
     * inputs have fixed tabs (BUILTIN_TABS); a tab with nothing on it is left out, while "content" and "settings"
     * always stay. Each slides field follows them as a main tab of its own ("slides-{name}", named by the field's
     * label), so a plain form with one has tabs too. An error counts on the tab that shows its input; the errors of
     * inputs the form does not show (a crafted post) are the layout's "unplaced" ones.
     *
     * @param EntryFormValues $values to count the chosen files of the media tab
     * @param array<string, string> $errors input => translation key, as the form shows them
     * @param bool $canPublish whether the user may publish (only then is the publication date shown)
     * @param bool $preset whether the form edits a preset page (no status or publication date; the address read-only)
     */
    public static function layout(Collection $collection, array $values, array $errors, bool $canPublish = true, bool $preset = false): FormLayout
    {
        $fields = array_fill_keys(Field::TABS, []);
        $lead = [];
        $own = [];
        foreach ($collection->fields as $field) {
            if (self::hasOwnTab($field)) {
                $own[] = $field;
            } elseif ($field->tab === 'settings' && $field->type instanceof TagsType) {
                // The tags above the built-ins: like the taxonomy pickers, they sit above the status (a user decision).
                $lead[] = $field;
            } else {
                $fields[$field->tab][] = $field;
            }
        }
        $shown = self::builtins($collection, $canPublish && !$preset);
        if ($preset) {
            $shown = array_values(array_diff($shown, ['status']));
        }
        $builtins = ['content' => [], 'media' => [], 'settings' => [], 'seo' => []];
        foreach ($shown as $builtin) {
            $builtins[self::BUILTIN_TABS[$builtin]][] = $builtin;
        }
        [$counts, $unplaced] = self::placeErrors($collection, $shown, $errors);
        $media = [];
        foreach ($fields['media'] as $field) {
            if ($field->type instanceof MediaFieldType) {
                $media = [...$media, ...self::mediaIds($values['fields'][$field->name] ?? null)];
            }
        }
        $tab = static fn(string $name): FormTab => new FormTab(
            $name,
            $builtins[$name],
            $fields[$name] ?? [],
            $counts[$name] ?? 0,
            $name === 'media' ? count($media) : 0,
            leadFields: $name === 'settings' ? $lead : [],
        );
        $filled = static fn(FormTab $tab): bool => $tab->builtins !== [] || $tab->fields !== [] || $tab->leadFields !== [];
        $ownTabs = array_map(
            static fn(Field $field): FormTab => new FormTab(self::ownTab($field), [], [$field], $counts[self::ownTab($field)] ?? 0, field: $field),
            $own,
        );

        return new FormLayout(
            [...array_filter([$tab('content'), $tab('media')], $filled), ...$ownTabs],
            array_values(array_filter([$tab('settings'), $tab('seo')], $filled)),
            $unplaced,
        );
    }

    /**
     * @return EntryFormValues
     */
    public static function defaults(): array
    {
        return [
            'title' => '',
            'slug' => '',
            'status' => '',
            'published_at' => '',
            'parent_id' => '',
            'position' => '',
            'meta_title' => '',
            'meta_description' => '',
            'fields' => [],
            'terms' => [],
        ];
    }

    /**
     * The picked term ids of the collection's taxonomies() and the tag names of its tags fields. An empty value is
     * the single-choice picker's "Choose…" option (the route taxonomy's select): no term.
     *
     * @param array<mixed> $body
     * @param array<string, mixed> $fields the submitted field values
     * @param array<string, string> $errors
     *
     * @return array{0: array<string, list<int|string>>, 1: array<string, list<string>>} the choices, and the picked ids to re-render the pickers
     */
    private static function terms(array $body, Collection $collection, array $fields, array &$errors): array
    {
        $submitted = is_array($body['terms'] ?? null) ? $body['terms'] : [];
        $choices = [];
        $picked = [];
        foreach ($collection->taxonomies as $taxonomy) {
            $raw = $submitted[$taxonomy] ?? [];
            $choices[$taxonomy] = [];
            $picked[$taxonomy] = [];
            foreach (is_array($raw) ? $raw : [$raw] as $value) {
                if ($value === '') {
                    continue;
                }
                if (is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) === 1) {
                    $choices[$taxonomy][] = (int) $value;
                    $picked[$taxonomy][] = $value;
                } else {
                    $errors['terms.' . $taxonomy] = 'validation.terms.invalid';
                }
            }
        }
        foreach ($collection->fields as $field) {
            if ($field->type instanceof TagsType && $field->taxonomy !== null) {
                $choices[$field->taxonomy] = TagsType::names($fields[$field->name] ?? null);
            }
        }

        return [$choices, $picked];
    }

    /**
     * The built-in inputs the form shows for this collection and user, in form order.
     *
     * @return list<string>
     */
    private static function builtins(Collection $collection, bool $canPublish): array
    {
        if ($collection->plain) {
            // Only the fields: the title, the status and the SEO texts are fixed (EntryService::plainInput()).
            return $collection->taxonomies !== [] ? ['terms'] : [];
        }
        $shown = [
            'title' => true,
            'terms' => $collection->taxonomies !== [],
            'status' => true,
            'published_at' => $canPublish,
            'parent' => $collection->hierarchical,
            'share' => $collection->route !== null,
            'meta_title' => $collection->route !== null,
            'meta_description' => $collection->route !== null,
            // The address last in its tab: the SEO texts come first (a user decision).
            'slug' => !$collection->single,
        ];

        return array_keys(array_filter($shown));
    }

    /**
     * @param list<string> $shown the built-in inputs the form shows
     * @param array<string, string> $errors
     *
     * @return array{0: array<string, int>, 1: list<string>} tab => the number of its inputs with an error, and the
     *                                                         error keys of inputs the form does not show
     */
    private static function placeErrors(Collection $collection, array $shown, array $errors): array
    {
        $counts = [];
        $unplaced = [];
        foreach (array_keys($errors) as $key) {
            if (in_array($key, self::FORM_ERRORS, true)) {
                continue;
            }
            if (str_starts_with($key, 'terms.')) {
                $taxonomy = substr($key, 6);
                $tab = $collection->tagsField($taxonomy)->tab
                    ?? (in_array('terms', $shown, true) && in_array($taxonomy, $collection->taxonomies, true) ? 'settings' : null);
            } elseif (($field = $collection->field($key)) !== null) {
                $tab = self::hasOwnTab($field) ? self::ownTab($field) : $field->tab;
            } else {
                $input = self::ERROR_INPUTS[$key] ?? $key;
                $tab = in_array($input, $shown, true) ? self::BUILTIN_TABS[$input] : null;
            }
            if ($tab === null) {
                $unplaced[] = $key;
            } else {
                $counts[$tab] = ($counts[$tab] ?? 0) + 1;
            }
        }

        return [$counts, $unplaced];
    }

    /**
     * A slides field never sits among the other fields: it gets a main-column tab of its own (a user decision of
     * Plan 30), whatever its Field::tab().
     */
    private static function hasOwnTab(Field $field): bool
    {
        return $field->type instanceof SlidesType;
    }

    private static function ownTab(Field $field): string
    {
        return 'slides-' . $field->name;
    }

    /**
     * The distinct media ids of a media field's value, read leniently (as media/field.php shows them).
     *
     * @return list<int>
     */
    private static function mediaIds(mixed $value): array
    {
        $items = is_int($value) ? [$value] : (is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []));
        $ids = [];
        foreach ($items as $item) {
            $item = is_int($item) ? (string) $item : (is_string($item) ? trim($item) : '');
            if (preg_match('/^[1-9]\d{0,9}$/', $item) === 1) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

}
