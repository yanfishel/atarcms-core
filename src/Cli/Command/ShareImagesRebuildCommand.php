<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\ShareImage\ShareImageOutcome;
use AtarCms\Content\ShareImage\ShareImages;
use AtarCms\Media\Share\ShareBrand;
use AtarCms\Media\Share\ShareFont;

/**
 * Brings every entry's generated share image up to date, like a save does: after changing the site name, the site
 * icon, the admin colour, app.url or the theme's share image layout. Only images whose inputs changed are drawn
 * again; entries that no longer qualify lose theirs.
 */
#[AsCommand('share-images:rebuild', 'Bring the share images of entries up to date (after a name, logo, colour or layout change): share-images:rebuild [--collection=<name>]')]
final class ShareImagesRebuildCommand implements Command
{
    public const int BATCH = 100;

    public function __construct(
        private readonly Collections $collections,
        private readonly EntryRepository $entries,
        private readonly ShareImages $shareImages,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $only = $input->option('collection');
        if (is_string($only) && $this->collections->find($only) === null) {
            $output->error(sprintf('Unknown collection "%s".', $only));

            return 1;
        }
        if (!ShareFont::available()) {
            $output->error('GD cannot draw text here: build PHP\'s GD extension with FreeType (--with-freetype).');

            return 1;
        }
        $names = is_string($only) ? [$only] : array_keys($this->collections->all());
        $counts = array_fill_keys(array_map(static fn(ShareImageOutcome $o): string => $o->value, ShareImageOutcome::cases()), 0);
        /** @var array<string, ShareBrand> $brands */
        $brands = [];
        $after = 0;
        while ($names !== []) {
            $batch = $this->entries->batchAfter($names, $after, self::BATCH);
            foreach ($batch as $entry) {
                $brand = $brands[$entry->locale] ??= $this->shareImages->brand($entry->locale);
                $counts[$this->shareImages->sync($entry, $brand)->outcome->value]++;
                $after = $entry->id;
            }
            if (count($batch) < self::BATCH) {
                break;
            }
        }
        $output->line(sprintf(
            'Made %d, unchanged %d, removed %d, failed %d.',
            $counts[ShareImageOutcome::Made->value],
            $counts[ShareImageOutcome::Unchanged->value],
            $counts[ShareImageOutcome::Removed->value],
            $counts[ShareImageOutcome::Failed->value],
        ));

        return 0;
    }
}
