<?php

declare(strict_types=1);

namespace AtarCms\Content\Preset;

/**
 * What PresetPages::sync() did for one preset page in one language: "created" it, "adopted" the page that had its
 * address, or "refused" (the address is used by something it may not take over; $reason is the translation key).
 */
final readonly class PresetChange
{
    public function __construct(
        public string $collection,
        public string $preset,
        public string $locale,
        public string $action,
        public ?int $entryId = null,
        public ?string $reason = null,
    ) {}

    /**
     * One line for the console ("Preset page created: pages/privacy (ru)").
     */
    public function line(): string
    {
        $what = sprintf('%s/%s (%s)', $this->collection, $this->preset, $this->locale);

        return match ($this->action) {
            'created' => 'Preset page created: ' . $what,
            'adopted' => 'Preset page adopted (the page at its address): ' . $what,
            default => sprintf('Preset page NOT created: %s: %s', $what, match ($this->reason) {
                'validation.slug.reserved' => 'a top-level section uses its address in this language; give the section another address and run "bin/atar migrate" again',
                'validation.slug.taken' => 'another page uses its address in this language; change that page\'s address and run "bin/atar migrate" again',
                'validation.entry.preset_adopt' => 'the page at its address in this language is a subpage or another preset page and cannot become this one; change that page\'s address and run "bin/atar migrate" again',
                'validation.locale.exists' => 'the translations of the page at its address already have a page in this language; change one of them and run "bin/atar migrate" again',
                'validation.locale.unsupported' => 'the collection does not take this language (not translatable, or not in config/locales.php)',
                default => 'it could not be saved in this language; check the pages at its address and run "bin/atar migrate" again',
            }),
        };
    }
}
