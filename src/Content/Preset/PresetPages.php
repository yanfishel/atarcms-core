<?php

declare(strict_types=1);

namespace AtarCms\Content\Preset;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\Locales;

/**
 * Makes every preset page (Collection::presets()) exist in every language of its collection: "bin/atar migrate" runs
 * sync() after the migrations, and "bin/atar demo:seed" after deleting the content. Idempotent: a page that exists
 * is left alone, so a second run changes nothing; a new language or a new preset gets its pages on the next run.
 * A missing page is created through EntryService (titled by its label, published, empty) in the translation group of
 * the preset's other languages. An entry already at the preset's address in that language, or the group's own
 * translation in it, is adopted instead (its address becomes the preset's, it stays as written and is published).
 * An address another owner of the root route uses in a language (a top-level section) is reported, never taken.
 */
final class PresetPages
{
    public function __construct(
        private readonly Collections $collections,
        private readonly EntryRepository $entries,
        private readonly EntryService $service,
        private readonly Locales $locales,
    ) {}

    /**
     * @return list<PresetChange> what changed, in config order then the default language first; empty when nothing did
     */
    public function sync(): array
    {
        // What exists is read first, for every preset; a page another run makes meanwhile shows up as a refused
        // write, then joins in (syncPreset()).
        $stored = [];
        foreach ($this->collections->all() as $collection) {
            foreach (array_keys($collection->presets) as $preset) {
                $stored[$collection->name][$preset] = $this->entries->presetPages($collection->name, $preset);
            }
        }
        $changes = [];
        foreach ($this->collections->all() as $collection) {
            foreach (array_keys($collection->presets) as $preset) {
                $changes = [...$changes, ...$this->syncPreset($collection, $preset, $stored[$collection->name][$preset])];
            }
        }

        return $changes;
    }

    /**
     * @param array<string, Entry> $stored locale => the preset's page, as read when the sync started
     *
     * @return list<PresetChange>
     */
    private function syncPreset(Collection $collection, string $preset, array $stored): array
    {
        $default = $this->locales->default();
        $locales = $collection->translatable
            ? [$default, ...array_values(array_diff($this->locales->all(), [$default]))]
            : [$default];
        $group = ($stored[$default] ?? ($stored === [] ? null : reset($stored)))?->translationGroup;
        $changes = [];
        foreach ($locales as $locale) {
            if (isset($stored[$locale])) {
                continue;
            }
            try {
                $existing = $this->candidate($collection, $preset, $locale, $group);
                $entry = $existing === null
                    ? $this->service->createPreset($collection, $preset, $locale, $group)
                    : $this->service->adoptPreset($existing, $preset, $group);
                $changes[] = new PresetChange($collection->name, $preset, $locale, $existing === null ? 'created' : 'adopted', $entry->id);
                $group ??= $entry->translationGroup;
            } catch (ValidationFailed $e) {
                // Another run made it meanwhile (the unique key refused this one): nothing to report, and the
                // languages after it join that page's translation group.
                $made = $this->entries->presetPages($collection->name, $preset)[$locale] ?? null;
                if ($made !== null) {
                    $group ??= $made->translationGroup;

                    continue;
                }
                $changes[] = new PresetChange($collection->name, $preset, $locale, 'refused', reason: array_values($e->errors)[0] ?? null);
            }
        }

        return $changes;
    }

    /**
     * The entry to adopt in $locale: the preset group's own translation there, else the entry at the preset's address.
     */
    private function candidate(Collection $collection, string $preset, string $locale, ?string $group): ?Entry
    {
        foreach ($group === null ? [] : $this->entries->translations($group) as $translation) {
            if ($translation->locale === $locale && $translation->collection->name === $collection->name) {
                return $translation;
            }
        }

        return $this->entries->findBySlug($collection->name, $locale, $preset);
    }
}
