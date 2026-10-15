<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\User;
use AtarCms\Content\Field\FieldError;
use AtarCms\Content\Field\RichTextType;
use AtarCms\Content\Field\TagsType;
use AtarCms\Content\ShareImage\ShareImages;
use AtarCms\Database\Connection;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Clock;
use AtarCms\Search\SearchIndexer;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;

/**
 * The only write path for entries: validation, slugs, publishing rules, revisions, the search index, the audit log and,
 * after each save, the generated share image.
 * Error values are translation keys; ICU parameters travel in ValidationFailed::$params.
 */
final class EntryService
{
    public const int MIN_POSITION = -2147483648;

    public const int MAX_POSITION = 2147483647;

    public function __construct(
        private readonly Connection $db,
        private readonly EntryRepository $entries,
        private readonly RevisionRepository $revisions,
        private readonly Locales $locales,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly ReservedSlugs $reservedSlugs,
        private readonly EntryTerms $entryTerms,
        private readonly EntryMedia $entryMedia,
        private readonly EntryAccess $access,
        private readonly Collections $collections,
        private readonly SearchIndexer $search,
        private readonly RichTextTheme $richText,
        private readonly Translator $translator,
        private readonly ShareImages $shareImages,
        private readonly SlugHistoryRepository $slugHistory,
    ) {}

    /**
     * @throws ValidationFailed
     */
    public function create(Collection $collection, string $locale, EntryInput $input, ?User $actor, ?string $ip, bool $autosave = false): Entry
    {
        $errors = new ValidationErrors();
        if (!$this->allowsLocale($collection, $locale)) {
            $errors->add('locale', 'validation.locale.unsupported');
        } elseif ($collection->single && $this->entries->first($collection->name, $locale) !== null) {
            $errors->add('locale', 'validation.single.exists');
        }
        $values = $this->values($collection, $locale, $input, null, false, $actor, $errors);
        // A new entry has no terms yet: every taxonomy is checked, so a required tags field is enforced even
        // when $input->terms omits its key entirely (a missing key counts as "no tags", not "keep the current").
        // validate() only returns null for a null $input; the array literal above is never null.
        /** @var array<string, list<int|string>> $terms */
        $terms = $this->entryTerms->validate(
            $collection,
            $locale,
            [...array_fill_keys($collection->termTaxonomies(), []), ...($input->terms ?? [])],
            false,
            $errors,
        );
        $errors->throwIfAny();

        $now = $this->clock->now();
        $row = [
            ...$values,
            'collection' => $collection->name,
            'locale' => $locale,
            // A translatable single has one entry per language, all one group: a new one joins the others.
            'translation_group' => $this->singleGroup($collection) ?? EntryRepository::newTranslationGroup(),
            'author_id' => $actor?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $entry = $this->db->transaction(function () use ($collection, $row, $terms, $actor, $ip, $autosave): Entry {
            $entry = $this->mustFind($this->insert($collection, $row));
            $this->entryTerms->store($entry, $terms, $actor, $ip);
            $this->storeMedia($entry);
            $this->search->index($entry);
            if ($autosave) {
                $this->revisions->recordAutosave($entry, $actor?->id, $this->entryTerms->ids($entry->id));
            } else {
                $this->revisions->record($entry, $actor?->id, $this->entryTerms->ids($entry->id));
            }
            $this->audit->record('entry.created', $actor?->id, 'entry', $entry->id, self::auditMeta($entry), $ip);

            return $entry;
        });

        return $autosave ? $entry : $this->shareImage($entry);
    }

    /**
     * Saves the complete new state of an entry; every save writes a revision. An autosave (the entry form saving a
     * draft by itself) shares one revision with the same author's previous autosaves since the last manual save
     * (RevisionRepository::recordAutosave()), audited "entry.autosaved" only when that revision is added.
     *
     * @throws ValidationFailed
     */
    public function update(Entry $entry, EntryInput $input, ?User $actor, ?string $ip, bool $autosave = false): Entry
    {
        return $this->save($entry, $input, $actor, $ip, $autosave ? 'entry.autosaved' : 'entry.updated', [], false, $autosave);
    }

    /**
     * The entry $input would save, built in memory for a preview: normalised like a save (sanitised rich text, the
     * theme's classes, checked relations and media, a generated slug) with its chosen terms, but nothing is written and
     * nothing is thrown. Best effort (PreviewEntry::$valid is false after any error): field values, media, relations,
     * terms and a parent that fail validation are left out, an invalid or taken slug and over-long texts stay as
     * typed, and an empty title shows the stored title, or the collection's label for a new entry.
     */
    public function preview(Collection $collection, string $locale, EntryInput $input, ?Entry $existing, ?User $actor): PreviewEntry
    {
        $errors = new ValidationErrors();
        $values = $this->values($collection, $locale, $input, $existing, false, $actor, $errors);
        $choices = $existing === null
            ? [...array_fill_keys($collection->termTaxonomies(), []), ...($input->terms ?? [])]
            : $input->terms;
        $terms = $this->entryTerms->validate($collection, $locale, $choices, false, $errors);
        $now = $this->clock->now();
        $title = $values['title'];
        if (!is_string($title) || $title === '') {
            $title = $existing->title ?? $this->translator->label($locale, $collection->label);
        }
        $parentId = $values['parent_id'];
        $parentId = is_int($parentId) ? $parentId : null;
        $parent = $parentId === null ? null : $this->entries->find($parentId);
        $metaTitle = $values['meta_title'];
        $metaDescription = $values['meta_description'];
        /** @var array<string, mixed> $data the normalised field values (fields()) */
        $data = is_array($values['data']) ? $values['data'] : [];
        $publishedAt = $values['published_at'];
        $slug = $values['slug'];
        $position = $values['position'];

        $entry = new Entry(
            id: $existing->id ?? 0,
            collection: $collection,
            locale: $locale,
            translationGroup: $existing->translationGroup ?? '',
            slug: is_string($slug) ? $slug : '',
            title: $title,
            status: $input->status,
            publishedAt: $publishedAt instanceof DateTimeImmutable ? $publishedAt : null,
            parentId: $parentId,
            position: is_int($position) ? $position : 0,
            authorId: $existing !== null ? $existing->authorId : $actor?->id,
            createdAt: $existing->createdAt ?? $now,
            updatedAt: $now,
            seo: new Seo(
                is_string($metaTitle) ? $metaTitle : null,
                is_string($metaDescription) ? $metaDescription : null,
            ),
            data: $data,
        );

        return new PreviewEntry(
            $entry,
            $this->entryTerms->chosen($collection, $locale, $terms, $existing, $now),
            $parent === null ? [] : [$parent->id => $parent] + $this->entries->ancestors([$parent->id]),
            $errors->isEmpty(),
        );
    }

    /**
     * Maps a unique-key violation on entries (a concurrent save) to the error the user can act on;
     * null for any other database error.
     */
    public static function duplicateError(PDOException $e, Collection $collection): ?ValidationFailed
    {
        if (($e->errorInfo[1] ?? null) !== 1062) {
            return null;
        }
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'entries_group_locale_unique'),
            str_contains($message, 'entries_collection_locale_preset_unique') => new ValidationFailed(['locale' => 'validation.locale.exists']),
            str_contains($message, 'entries_collection_locale_slug_unique') => new ValidationFailed(
                $collection->single ? ['locale' => 'validation.single.exists'] : ['slug' => 'validation.slug.taken'],
            ),
            default => null,
        };
    }

    /**
     * Maps a foreign key violation of a chosen medium (deleted after the form was validated) to an error the user
     * can act on; null for any other database error.
     */
    public static function mediaGoneError(PDOException $e): ?ValidationFailed
    {
        if (($e->errorInfo[1] ?? null) !== 1452) {
            return null;
        }
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'entry_media_media_fk') => new ValidationFailed(['entry' => 'validation.media.gone']),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $meta extra audit metadata
     * @param bool $restore drop unknown relation and term ids instead of failing (restoring a revision)
     *
     * @throws ValidationFailed
     */
    private function save(Entry $entry, EntryInput $input, ?User $actor, ?string $ip, string $action, array $meta, bool $restore, bool $autosave = false): Entry
    {
        $errors = new ValidationErrors();
        $values = $this->values($entry->collection, $entry->locale, $input, $entry, $restore, $actor, $errors);
        $terms = $this->entryTerms->validate($entry->collection, $entry->locale, $input->terms, $restore, $errors);
        $errors->throwIfAny();

        $updated = $this->db->transaction(function () use ($entry, $values, $terms, $actor, $ip, $action, $meta, $autosave): Entry {
            $now = $this->clock->now();
            if (($values['slug'] ?? $entry->slug) !== $entry->slug && $entry->isPublicAt($now)) {
                // A published entry's address had the old slug: it answers 301 to the new one (a draft had none).
                $this->slugHistory->recordEntry($entry->collection->name, $entry->locale, $entry->slug, $entry->id, $now);
            }
            try {
                $this->entries->update($entry->id, [...$values, 'updated_at' => $this->clock->now()]);
            } catch (PDOException $e) {
                throw self::duplicateError($e, $entry->collection) ?? self::mediaGoneError($e) ?? $e;
            }
            if ($terms !== null) {
                $this->entryTerms->store($entry, $terms, $actor, $ip);
            }
            $updated = $this->mustFind($entry->id);
            $this->storeMedia($updated);
            $this->search->index($updated);
            // An autosave that only overwrites the author's running autosave revision is not audited again.
            $added = $autosave
                ? $this->revisions->recordAutosave($updated, $actor?->id, $this->entryTerms->ids($updated->id))
                : $this->revisions->record($updated, $actor?->id, $this->entryTerms->ids($updated->id)) > 0;
            if ($added) {
                $this->audit->record($action, $actor?->id, 'entry', $updated->id, [...self::auditMeta($updated), ...$meta], $ip);
            }

            return $updated;
        });

        return $autosave ? $updated : $this->shareImage($updated);
    }

    /**
     * Deletes one locale of an entry together with its revisions.
     *
     * @throws ValidationFailed while the entry has subpages, and always for a preset page
     */
    public function delete(Entry $entry, ?User $actor, ?string $ip): void
    {
        if ($entry->isPreset()) {
            throw new ValidationFailed(['entry' => 'validation.entry.preset']);
        }
        if ($this->entries->hasChildren($entry->id)) {
            throw new ValidationFailed(['entry' => 'validation.entry.has_children']);
        }

        try {
            $this->db->transaction(function () use ($entry, $actor, $ip): void {
                $this->entries->delete($entry->id);
                $this->audit->record('entry.deleted', $actor?->id, 'entry', $entry->id, [...self::auditMeta($entry), 'slug' => $entry->slug], $ip);
            });
        } catch (PDOException $e) {
            // A subpage created meanwhile: the parent foreign key (ON DELETE RESTRICT) refuses the delete.
            if (($e->errorInfo[1] ?? null) === 1451) {
                throw new ValidationFailed(['entry' => 'validation.entry.has_children']);
            }

            throw $e;
        }
        $this->shareImages->remove($entry);
    }

    /**
     * The translation group of a translatable single's existing entries, null when it has none (or is no such single).
     */
    private function singleGroup(Collection $collection): ?string
    {
        if (!$collection->single || !$collection->translatable) {
            return null;
        }
        foreach ($this->locales->all() as $locale) {
            $entry = $this->entries->first($collection->name, $locale);
            if ($entry !== null) {
                return $entry->translationGroup;
            }
        }

        return null;
    }

    /**
     * Copies $source into $locale as a draft of the same translation group.
     *
     * @throws ValidationFailed
     */
    public function createTranslation(Entry $source, string $locale, ?User $actor, ?string $ip): Entry
    {
        $collection = $source->collection;
        $errors = new ValidationErrors();
        if (!$collection->translatable || $locale === $source->locale || !$this->allowsLocale($collection, $locale)) {
            $errors->add('locale', 'validation.locale.unsupported');
        } elseif (array_filter($this->entries->translations($source->translationGroup), static fn(Entry $t): bool => $t->locale === $locale) !== []) {
            $errors->add('locale', 'validation.locale.exists');
        } elseif ($collection->single && $this->entries->first($collection->name, $locale) !== null) {
            $errors->add('locale', 'validation.single.exists');
        }
        $errors->throwIfAny();

        $parentId = $this->translatedParent($source, $locale);
        $now = $this->clock->now();
        $row = [
            'collection' => $collection->name,
            'locale' => $locale,
            'translation_group' => $source->translationGroup,
            'slug' => $collection->single ? $source->slug : $this->freeSlug($collection, $locale, $source->slug, $parentId === null),
            'title' => $source->title,
            'status' => EntryStatus::Draft->value,
            'published_at' => null,
            'parent_id' => $parentId,
            'position' => $source->position,
            'author_id' => $actor?->id,
            'created_at' => $now,
            'updated_at' => $now,
            'meta_title' => $source->seo->metaTitle,
            'meta_description' => $source->seo->metaDescription,
            'data' => $source->data,
        ];
        if ($collection->plain) {
            // A plain page is never a draft: the translation is published at once, titled in its own language.
            $row = [...$row, 'title' => $this->translator->label($locale, $collection->label), 'status' => EntryStatus::Published->value, 'published_at' => $now];
        }
        if ($source->isPreset()) {
            // A preset page's translation is the same preset at the same address, published, titled by its label.
            $preset = (string) $source->preset;
            if ($this->reservedSlugs->blocksPreset($collection, $preset, $locale)) {
                throw new ValidationFailed(['slug' => 'validation.slug.reserved']);
            }
            $row = [
                ...$row,
                'slug' => $preset,
                'preset' => $preset,
                'title' => $this->translator->label($locale, $collection->presets[$preset]),
                'status' => EntryStatus::Published->value,
                'published_at' => $now,
            ];
        }

        $entry = $this->db->transaction(function () use ($collection, $row, $source, $actor, $ip): Entry {
            $entry = $this->mustFind($this->insert($collection, $row));
            $this->entryTerms->store($entry, $this->entryTerms->translated($source, $entry->locale), $actor, $ip);
            $this->storeMedia($entry);
            $this->search->index($entry);
            $this->revisions->record($entry, $actor?->id, $this->entryTerms->ids($entry->id));
            $this->audit->record('entry.translated', $actor?->id, 'entry', $entry->id, [...self::auditMeta($entry), 'source' => $source->id], $ip);

            return $entry;
        });

        return $this->shareImage($entry);
    }

    /**
     * Creates the preset page $preset of $collection in $locale (PresetPages): at its address, titled by its label in
     * $locale, published, with empty fields, in the translation group $group (a new one when null). A system write: no
     * author, audited without an actor.
     *
     * @throws ValidationFailed when another page has the address (validation.slug.taken), another owner of the root
     *                          route uses it in $locale (validation.slug.reserved) or the preset exists there already
     */
    public function createPreset(Collection $collection, string $preset, string $locale, ?string $group): Entry
    {
        $label = $collection->presets[$preset] ?? throw new InvalidArgumentException(sprintf('Collection "%s" has no preset "%s"', $collection->name, $preset));
        if (!$this->allowsLocale($collection, $locale)) {
            throw new ValidationFailed(['locale' => 'validation.locale.unsupported']);
        }
        if ($this->reservedSlugs->blocksPreset($collection, $preset, $locale)) {
            throw new ValidationFailed(['slug' => 'validation.slug.reserved']);
        }
        if ($this->entries->slugExists($collection->name, $locale, $preset)) {
            throw new ValidationFailed(['slug' => 'validation.slug.taken']);
        }
        $now = $this->clock->now();
        $row = [
            'collection' => $collection->name,
            'locale' => $locale,
            'translation_group' => $group ?? EntryRepository::newTranslationGroup(),
            'slug' => $preset,
            'title' => $this->translator->label($locale, $label),
            'status' => EntryStatus::Published->value,
            'published_at' => $now,
            'parent_id' => null,
            'position' => 0,
            'author_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'preset' => $preset,
            'data' => [],
        ];

        $entry = $this->db->transaction(function () use ($collection, $row, $preset): Entry {
            $entry = $this->mustFind($this->insert($collection, $row));
            $this->search->index($entry);
            $this->revisions->record($entry, null);
            $this->audit->record('entry.created', null, 'entry', $entry->id, [...self::auditMeta($entry), 'preset' => $preset]);

            return $entry;
        });

        return $this->shareImage($entry);
    }

    /**
     * Makes an existing top-level entry of $collection the preset page $preset (PresetPages): its address becomes the
     * preset's, it is published from now on (a past publication date stays) and, with a $group, it joins that
     * translation group. Its title, texts and fields stay. A system write, audited "entry.preset_adopted" without an
     * actor.
     *
     * @throws ValidationFailed when the address is taken or reserved in its locale, or $group has its locale already
     */
    public function adoptPreset(Entry $entry, string $preset, ?string $group): Entry
    {
        $collection = $entry->collection;
        if (!isset($collection->presets[$preset])) {
            throw new InvalidArgumentException(sprintf('Collection "%s" has no preset "%s"', $collection->name, $preset));
        }
        if ($entry->parentId !== null || $entry->isPreset()) {
            throw new ValidationFailed(['entry' => 'validation.entry.preset_adopt']);
        }
        if ($entry->slug !== $preset) {
            if ($this->reservedSlugs->blocksPreset($collection, $preset, $entry->locale)) {
                throw new ValidationFailed(['slug' => 'validation.slug.reserved']);
            }
            if ($this->entries->slugExists($collection->name, $entry->locale, $preset, $entry->id)) {
                throw new ValidationFailed(['slug' => 'validation.slug.taken']);
            }
        }
        $group ??= $entry->translationGroup;
        if ($group !== $entry->translationGroup) {
            foreach ($this->entries->translations($group) as $translation) {
                if ($translation->locale === $entry->locale) {
                    throw new ValidationFailed(['locale' => 'validation.locale.exists']);
                }
            }
        }
        $now = $this->clock->now();
        $publishedAt = $entry->publishedAt !== null && $entry->publishedAt <= $now ? $entry->publishedAt : $now;

        $adopted = $this->db->transaction(function () use ($entry, $preset, $group, $publishedAt, $now): Entry {
            try {
                $this->entries->update($entry->id, [
                    'preset' => $preset,
                    'slug' => $preset,
                    'status' => EntryStatus::Published->value,
                    'published_at' => $publishedAt,
                    'translation_group' => $group,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $e) {
                throw self::duplicateError($e, $entry->collection) ?? $e;
            }
            $adopted = $this->mustFind($entry->id);
            $this->search->index($adopted);
            $this->revisions->record($adopted, null, $this->entryTerms->ids($adopted->id));
            $this->audit->record('entry.preset_adopted', null, 'entry', $adopted->id, [...self::auditMeta($adopted), 'preset' => $preset, 'slug' => $entry->slug]);

            return $adopted;
        });

        return $this->shareImage($adopted);
    }

    /**
     * Saves the title, slug, fields, SEO and terms of a revision as a new save; publishing state and tree place stay.
     *
     * @throws ValidationFailed
     */
    public function restore(Entry $entry, int $revisionId, ?User $actor, ?string $ip): Entry
    {
        $revision = $this->revisions->find($entry->id, $revisionId)
            ?? throw new ValidationFailed(['revision' => 'validation.revision.unknown']);
        $snapshot = $revision->snapshot;
        $data = $snapshot['data'] ?? [];
        /** @var array<string, mixed> $fields */
        $fields = is_array($data) ? $data : [];

        return $this->save($entry, new EntryInput(
            title: self::snapshotString($snapshot, 'title'),
            slug: self::snapshotString($snapshot, 'slug'),
            status: $entry->status,
            publishedAt: $entry->publishedAt,
            parentId: $entry->parentId,
            position: $entry->position,
            fields: $fields,
            metaTitle: self::snapshotString($snapshot, 'meta_title'),
            metaDescription: self::snapshotString($snapshot, 'meta_description'),
            terms: $this->entryTerms->fromSnapshot($entry->collection, $snapshot['terms'] ?? null),
        ), $actor, $ip, 'entry.restored', ['revision' => $revision->id], true);
    }

    /**
     * Moves the entries of a hierarchical collection in one locale to the submitted tree, atomically: the rows are
     * locked, $version must still match the stored order (validation.tree.stale), $tree must hold exactly the stored
     * entries (validation.tree.invalid) at most TreeOrder::MAX_DEPTH levels deep, every entry whose parent or
     * position changes must pass $mayMove (validation.tree.not_allowed), and an entry that becomes top-level must not
     * use a reserved slug (validation.tree.reserved). Moved entries get a new updated_at, so edit forms opened before
     * the move turn stale; no revisions are written (a revision restores content, not the place in the tree).
     *
     * @param list<TreeNode> $tree
     * @param Closure(Entry): bool $mayMove
     *
     * @return int the number of entries that moved
     *
     * @throws ValidationFailed
     */
    public function reorder(Collection $collection, string $locale, array $tree, string $version, Closure $mayMove, ?User $actor, ?string $ip): int
    {
        if (!$collection->hierarchical || $collection->single) {
            throw new InvalidArgumentException(sprintf('Collection "%s" is not hierarchical', $collection->name));
        }
        if (!$this->allowsLocale($collection, $locale)) {
            throw new ValidationFailed(['locale' => 'validation.locale.unsupported']);
        }

        return $this->db->transaction(function () use ($collection, $locale, $tree, $version, $mayMove, $actor, $ip): int {
            $entries = $this->entries->forTree($collection->name, $locale, true);
            if (!hash_equals(TreeOrder::version($entries), $version)) {
                throw new ValidationFailed(['tree' => 'validation.tree.stale']);
            }
            $placements = TreeOrder::placements($tree, array_map(static fn(Entry $entry): int => $entry->id, $entries), TreeOrder::MAX_DEPTH);
            $moved = TreeOrder::changed($entries, $placements);
            foreach ($moved as $entry) {
                if (!$mayMove($entry)) {
                    throw new ValidationFailed(['tree' => 'validation.tree.not_allowed'], ['tree' => ['title' => $entry->title]]);
                }
                $toTopLevel = $placements[$entry->id]['parentId'] === null && $entry->parentId !== null;
                if ($toTopLevel && $this->reservedSlugs->isReserved($collection, $entry->slug, $locale)) {
                    throw new ValidationFailed(['tree' => 'validation.tree.reserved'], ['tree' => ['title' => $entry->title]]);
                }
            }
            $now = $this->clock->now();
            foreach ($moved as $entry) {
                $this->entries->update($entry->id, [
                    'parent_id' => $placements[$entry->id]['parentId'],
                    'position' => $placements[$entry->id]['position'],
                    'updated_at' => $now,
                ]);
            }
            if ($moved !== []) {
                $this->audit->record('entry.reordered', $actor?->id, 'collection', $collection->name, [
                    'collection' => $collection->name,
                    'locale' => $locale,
                    'moved' => count($moved),
                ], $ip);
            }

            return count($moved);
        });
    }

    private function allowsLocale(Collection $collection, string $locale): bool
    {
        return $this->locales->isSupported($locale) && ($collection->translatable || $locale === $this->locales->default());
    }

    /**
     * Validates and normalizes everything except the identity columns (collection, locale, group, author, dates).
     *
     * @return array<string, mixed>
     */
    private function values(Collection $collection, string $locale, EntryInput $input, ?Entry $existing, bool $restore, ?User $actor, ValidationErrors $errors): array
    {
        if ($collection->plain) {
            $input = $this->plainInput($collection, $locale, $input, $existing);
        }
        if ($existing !== null && $existing->isPreset()) {
            $input = $this->presetInput($existing, $input);
        }
        $title = self::text($input->title, 255, 'title', $errors);
        if ($title === null) {
            $errors->add('title', 'validation.field.required');
        }
        $slug = $this->slug($collection, $locale, $input, $title ?? '', $existing, $errors);

        $publishedAt = $input->publishedAt;
        if ($input->status === EntryStatus::Published && $publishedAt === null) {
            $publishedAt = $existing === null ? $this->clock->now() : ($existing->publishedAt ?? $this->clock->now());
        }
        if ($input->status === EntryStatus::Scheduled && $publishedAt === null) {
            $errors->add('published_at', 'validation.published_at.required');
        }
        if ($input->position < self::MIN_POSITION || $input->position > self::MAX_POSITION) {
            $errors->add('position', 'validation.field.invalid');
        }

        $data = $this->fields($collection, $input->fields, $existing, $restore, $actor, $errors);
        $data = $this->entryMedia->validate($collection, $data, $restore, $errors);

        return [
            'slug' => $slug,
            'title' => $title ?? '',
            'status' => $input->status->value,
            'published_at' => $publishedAt,
            'parent_id' => $this->parent($collection, $locale, $input->parentId, $existing, $errors),
            'position' => $input->position,
            'data' => $data,
            'meta_title' => self::text($input->metaTitle, 255, 'meta_title', $errors),
            'meta_description' => self::text($input->metaDescription, 500, 'meta_description', $errors),
        ];
    }

    /**
     * What a plain() single stores whatever is posted: the collection's label in $locale as the title, published
     * (keeping a past publication date), no SEO texts or image; the fields and terms as submitted.
     */
    private function plainInput(Collection $collection, string $locale, EntryInput $input, ?Entry $existing): EntryInput
    {
        $now = $this->clock->now();
        $publishedAt = $existing?->publishedAt;

        return new EntryInput(
            title: $this->translator->label($locale, $collection->label),
            status: EntryStatus::Published,
            publishedAt: $publishedAt !== null && $publishedAt <= $now ? $publishedAt : $now,
            fields: $input->fields,
            terms: $input->terms,
        );
    }

    /**
     * What a preset page stores whatever is posted (a crafted slug or status is ignored, like a plain single's): its
     * address, published (keeping a past publication date), at the top level; the title, texts, fields and terms as
     * submitted.
     */
    private function presetInput(Entry $existing, EntryInput $input): EntryInput
    {
        $now = $this->clock->now();
        $publishedAt = $existing->publishedAt;

        return new EntryInput(
            title: $input->title,
            slug: $existing->slug,
            status: EntryStatus::Published,
            publishedAt: $publishedAt !== null && $publishedAt <= $now ? $publishedAt : $now,
            position: $input->position,
            fields: $input->fields,
            metaTitle: $input->metaTitle,
            metaDescription: $input->metaDescription,
            terms: $input->terms,
        );
    }

    private function slug(Collection $collection, string $locale, EntryInput $input, string $title, ?Entry $existing, ValidationErrors $errors): string
    {
        if ($collection->single) {
            return str_replace('_', '-', $collection->name);
        }
        if ($existing !== null && $existing->isPreset()) {
            // A preset's own address, reserved for it (ReservedSlugs).
            return $existing->slug;
        }
        $topLevel = $input->parentId === null;
        $slug = trim($input->slug);
        if ($slug === '') {
            // An empty slug generates one from the title for a new entry and for a draft (the form of a new entry keeps
            // an empty slug input after its first autosave, so the address follows the title until the entry is
            // public); a published or scheduled entry keeps its address.
            return $existing !== null && $existing->status !== EntryStatus::Draft
                ? $existing->slug
                : $this->freeSlug($collection, $locale, Slug::fromText($title) ?: 'entry', $topLevel, $existing?->id);
        }
        if (!Slug::isValid($slug)) {
            $errors->add('slug', 'validation.slug.invalid');
        } elseif ($topLevel && $this->reservedSlugs->isReserved($collection, $slug, $locale)) {
            $errors->add('slug', 'validation.slug.reserved');
        } elseif ($this->entries->slugExists($collection->name, $locale, $slug, $existing?->id)) {
            $errors->add('slug', 'validation.slug.taken');
        }

        return $slug;
    }

    /**
     * @param int|null $exceptId the entry whose own slug does not count as taken
     */
    private function freeSlug(Collection $collection, string $locale, string $base, bool $topLevel, ?int $exceptId = null): string
    {
        $slug = $base;
        for (
            $n = 2;
            $this->entries->slugExists($collection->name, $locale, $slug, $exceptId)
                || ($topLevel && $this->reservedSlugs->isReserved($collection, $slug, $locale));
            $n++
        ) {
            $slug = Slug::withSuffix($base, $n);
        }

        return $slug;
    }

    private function translatedParent(Entry $source, string $locale): ?int
    {
        $parent = $source->parentId === null ? null : $this->entries->find($source->parentId);
        foreach ($parent === null ? [] : $this->entries->translations($parent->translationGroup) as $translation) {
            if ($translation->locale === $locale) {
                return $translation->id;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $snapshot
     */
    private static function snapshotString(array $snapshot, string $key): string
    {
        $value = $snapshot[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function parent(Collection $collection, string $locale, ?int $parentId, ?Entry $existing, ValidationErrors $errors): ?int
    {
        if ($parentId === null) {
            return null;
        }
        $parent = $collection->hierarchical ? $this->entries->find($parentId) : null;
        if (
            $parent === null
            || $parent->collection->name !== $collection->name
            || $parent->locale !== $locale
            || ($existing !== null && $this->isInChain($parent, $existing->id))
        ) {
            $errors->add('parent_id', 'validation.parent.invalid');

            return null;
        }

        return $parentId;
    }

    /**
     * Whether $entryId is $start or one of its ancestors; an existing cycle also counts as true.
     */
    private function isInChain(Entry $start, int $entryId): bool
    {
        $seen = [];
        for ($current = $start; $current !== null; $current = $current->parentId === null ? null : $this->entries->find($current->parentId)) {
            if ($current->id === $entryId || isset($seen[$current->id])) {
                return true;
            }
            $seen[$current->id] = true;
        }

        return false;
    }

    /**
     * Relation ids must exist in the target collection; media ids are checked afterwards by EntryMedia::validate(). On update, ids the entry already stored whose entries
     * were deleted since are dropped, but any other unknown id (a tampered or stale form) is an error; restoring
     * a revision drops every unknown id. An id the entry did not store yet also needs an $actor who can view the
     * target collection (a null $actor is a system write, restoring a revision keeps its ids). Tags fields are
     * skipped: their terms live in entry_terms (EntryTerms). Rich text keeps only the classes and fonts of the active
     * theme (RichTextTheme), restored revisions included.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function fields(Collection $collection, array $input, ?Entry $existing, bool $restore, ?User $actor, ValidationErrors $errors): array
    {
        $data = [];
        foreach ($collection->fields as $name => $field) {
            if ($field->type instanceof TagsType) {
                continue;
            }
            try {
                $value = $field->type->normalize($input[$name] ?? null, $field);
            } catch (FieldError $e) {
                $errors->add($name, $e->key, $e->params);

                continue;
            }
            if ($field->type instanceof RichTextType && is_string($value)) {
                $value = $this->richText->filter($value, $field->minimal);
            }
            if ($value === null) {
                if ($field->required) {
                    $errors->add($name, 'validation.field.required');
                }

                continue;
            }
            if ($field->target !== null && is_array($value)) {
                $ids = array_values(array_filter($value, is_int(...)));
                $stored = $existing?->data[$name] ?? [];
                $storedIds = array_values(array_filter(is_array($stored) ? $stored : [], is_int(...)));
                $added = array_diff($ids, $storedIds);
                if (!$restore && $actor !== null && $added !== [] && !$this->access->canView($actor, $this->collections->get($field->target))) {
                    $errors->add($name, 'validation.field.relation');

                    continue;
                }
                $found = $this->entries->existingIds($field->target, $ids);
                $missing = array_values(array_diff($ids, $found));
                if ($missing !== []) {
                    if (!$restore && array_diff($missing, $storedIds) !== []) {
                        $errors->add($name, 'validation.field.relation');

                        continue;
                    }
                    // Related entries deleted since the last save: drop them instead of blocking the save.
                    $value = array_values(array_filter($value, static fn(mixed $id): bool => in_array($id, $found, true)));
                    if ($value === []) {
                        if ($field->required) {
                            $errors->add($name, 'validation.field.required');
                        }

                        continue;
                    }
                }
            }
            $data[$name] = $value;
        }

        return $data;
    }

    /**
     * Single-line text: control characters and whitespace collapsed to single spaces, '' becomes null.
     */
    private static function text(string $value, int $max, string $field, ValidationErrors $errors): ?string
    {
        $value = preg_replace(['/[\x00-\x1F\x7F]+/', '/\s+/u'], ' ', $value);
        if ($value === null) {
            $errors->add($field, 'validation.field.invalid');

            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors->add($field, 'validation.field.too_long', ['max' => $max]);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws ValidationFailed on a unique-key violation
     */
    private function insert(Collection $collection, array $row): int
    {
        try {
            return $this->entries->insert($row);
        } catch (PDOException $e) {
            throw self::duplicateError($e, $collection) ?? self::mediaGoneError($e) ?? $e;
        }
    }

    private function mustFind(int $id): Entry
    {
        return $this->entries->find($id) ?? throw new NotFound(sprintf('Entry #%d no longer exists', $id));
    }

    /**
     * Makes, keeps or removes the entry's generated share image; runs after the entry's transaction committed (never
     * inside it: drawing takes time and a failure must not undo the save) and never throws (ShareImages::sync()).
     */
    private function shareImage(Entry $entry): Entry
    {
        return $entry->withShareImage($this->shareImages->sync($entry)->name);
    }

    /**
     * Records the entry's media references; runs inside the entry's transaction.
     */
    private function storeMedia(Entry $entry): void
    {
        try {
            $this->entryMedia->store($entry);
        } catch (PDOException $e) {
            throw self::mediaGoneError($e) ?? $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function auditMeta(Entry $entry): array
    {
        return [
            'collection' => $entry->collection->name,
            'locale' => $entry->locale,
            'title' => $entry->title,
            'status' => $entry->status->value,
        ];
    }
}
