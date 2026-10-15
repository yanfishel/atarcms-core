<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Preset\PresetChange;
use AtarCms\Content\Preset\PresetPages;
use AtarCms\Content\PublicContent;
use AtarCms\Content\TermInput;
use AtarCms\I18n\Locales;
use AtarCms\Tests\Support\Cast;
use DateTimeImmutable;
use PDOException;

/**
 * Preset pages (Collection::presets(); the fixture's "info" has "legal"): PresetPages creates or adopts them in every
 * language, and EntryService keeps their three locks: never deleted, the address fixed, always published.
 */
final class PresetPagesTest extends ContentTestCase
{
    public function testSyncCreatesEveryMissingPresetOnceInEveryLanguage(): void
    {
        $changes = $this->presets()->sync();

        self::assertSame(['created info/legal en', 'created info/legal ru'], self::lines($changes));
        $en = $this->preset('en');
        $ru = $this->preset('ru');
        self::assertSame(['legal', 'legal'], [$en->slug, $ru->slug], 'the same address in every language');
        self::assertSame(['Legal notice', 'Правовая информация'], [$en->title, $ru->title], 'titled by the label in each language');
        self::assertSame($en->translationGroup, $ru->translationGroup, 'translations of each other');
        self::assertSame([EntryStatus::Published, EntryStatus::Published], [$en->status, $ru->status]);
        self::assertEquals($this->clock->now(), $en->publishedAt);
        self::assertSame([], $en->data);
        self::assertNull($en->authorId);
        self::assertTrue($en->isPreset());
        self::assertSame(['entry.created', 'entry.created'], $this->auditActions());

        self::assertSame([], $this->presets()->sync(), 'a second run changes nothing');
        self::assertSame(2, Cast::int($this->db->scalar("SELECT COUNT(*) FROM entries WHERE collection = 'info'")));
    }

    public function testANewLanguageGetsItsPresetsLinkedToTheOthers(): void
    {
        $this->presets(new Locales('en', ['en']))->sync();

        self::assertSame(['created info/legal ru'], self::lines($this->presets()->sync()));
        self::assertSame($this->preset('en')->translationGroup, $this->preset('ru')->translationGroup);
    }

    public function testAnExistingPageAtThePresetAddressIsAdoptedAndLinked(): void
    {
        $this->insertEntry(['collection' => 'info', 'locale' => 'ru', 'slug' => 'legal', 'title' => 'Право', 'status' => 'draft', 'published_at' => null, 'data' => ['body' => '<p>Текст</p>']]);

        $changes = $this->presets()->sync();

        self::assertSame(['created info/legal en', 'adopted info/legal ru'], self::lines($changes));
        $ru = $this->preset('ru');
        self::assertSame('Право', $ru->title, 'its own title and text stay');
        self::assertSame('<p>Текст</p>', $ru->data['body'] ?? null);
        self::assertSame(EntryStatus::Published, $ru->status, 'published from now on');
        self::assertSame($this->preset('en')->translationGroup, $ru->translationGroup);
        self::assertSame([], $this->presets()->sync());
    }

    public function testTheTranslationOfAnAdoptedPageIsAdoptedAndMovedToThePresetAddress(): void
    {
        $group = 'aaaabbbbccccddddaaaabbbbccccdddd';
        $this->insertEntry(['collection' => 'info', 'locale' => 'en', 'slug' => 'legal', 'title' => 'Legal', 'translation_group' => $group]);
        $this->insertEntry(['collection' => 'info', 'locale' => 'ru', 'slug' => 'pravo', 'title' => 'Право', 'translation_group' => $group]);

        self::assertSame(['adopted info/legal en', 'adopted info/legal ru'], self::lines($this->presets()->sync()));
        self::assertSame('legal', $this->preset('ru')->slug);
        self::assertSame($group, $this->preset('ru')->translationGroup);
    }

    /**
     * Two "migrate" runs at once: the other run makes the English "legal" after this one read what exists (here a
     * trigger makes it while this run creates its first preset), so this run's English write is refused; Russian then
     * joins the other run's page, never a translation group of its own.
     */
    public function testAPresetAnotherRunMadeMeanwhileIsJoined(): void
    {
        $collections = new Collections([
            ...array_values(array_filter($this->collections->all(), static fn(Collection $c): bool => $c->name !== 'info')),
            Collection::make('info')->route('/{slug}')->translatable()->presets(['alpha' => 'Alpha', 'legal' => 'site.presets.legal'])->fields([Field::richText('body')]),
        ], $this->collections->taxonomies);
        $this->db->execute(
            "CREATE TRIGGER other_run AFTER INSERT ON search_index FOR EACH ROW BEGIN
                IF (SELECT preset FROM entries WHERE id = NEW.entry_id) = 'alpha' AND NOT EXISTS (SELECT 1 FROM entries WHERE preset = 'legal') THEN
                    INSERT INTO entries (collection, locale, translation_group, slug, title, status, published_at, position, created_at, updated_at, preset, data)
                    VALUES ('info', 'en', 'cccccccccccccccccccccccccccccccc', 'legal', 'Legal notice', 'published', NOW(), 0, NOW(), NOW(), 'legal', '{}');
                END IF;
            END",
        );

        $changes = new PresetPages($collections, $this->entries, $this->service(), new Locales('en', ['en', 'ru']))->sync();

        self::assertSame(['created info/alpha en', 'created info/alpha ru', 'created info/legal ru'], self::lines($changes), 'the lost English write is not reported');
        self::assertSame('cccccccccccccccccccccccccccccccc', $this->preset('ru')->translationGroup, 'Russian joins the other run\'s page');
    }

    public function testASectionAtThePresetAddressIsReportedAndLeftAlone(): void
    {
        $this->insertTerm(['taxonomy' => 'sections', 'locale' => 'ru', 'slug' => 'legal', 'name' => 'Право']);

        $changes = $this->presets()->sync();

        self::assertSame(['created info/legal en', 'refused info/legal ru'], self::lines($changes));
        self::assertSame('validation.slug.reserved', $changes[1]->reason);
        self::assertNull($this->entries->findBySlug('info', 'ru', 'legal'));
    }

    public function testTheUniqueKeyKeepsOnePresetPerLanguage(): void
    {
        $this->presets()->sync();

        $this->expectException(PDOException::class);
        $this->insertEntry(['collection' => 'info', 'locale' => 'en', 'slug' => 'other', 'preset' => 'legal']);
    }

    public function testAPresetIsNeverDeleted(): void
    {
        $this->presets()->sync();
        $entry = $this->preset('en');

        self::assertFalse($this->entryAccess()->canDelete($this->user(), $entry));
        self::assertValidation(['entry' => 'validation.entry.preset'], fn() => $this->service()->delete($entry, null, null));
        self::assertNotNull($this->entries->find($entry->id));
    }

    public function testASaveKeepsTheAddressAndPublishesWhateverIsPosted(): void
    {
        $this->presets()->sync();
        $entry = $this->preset('en');
        $this->clock->advance('+1 day');

        $saved = $this->service()->update($entry, new EntryInput(
            title: 'Legal information',
            slug: 'elsewhere',
            status: EntryStatus::Draft,
            publishedAt: new DateTimeImmutable('2031-01-01 00:00:00'),
            fields: ['body' => '<p>Terms</p>'],
            metaTitle: 'Legal',
        ), null, null);

        self::assertSame('legal', $saved->slug);
        self::assertSame(EntryStatus::Published, $saved->status);
        self::assertEquals($entry->publishedAt, $saved->publishedAt, 'the first publication date stays');
        self::assertSame('Legal information', $saved->title, 'the title and the text are the editor\'s');
        self::assertSame('<p>Terms</p>', $saved->data['body'] ?? null);
        self::assertSame('Legal', $saved->seo->metaTitle);
    }

    public function testARestoredRevisionKeepsTheAddressAndThePublication(): void
    {
        $this->insertEntry(['collection' => 'info', 'locale' => 'en', 'slug' => 'legal', 'title' => 'Old', 'status' => 'draft', 'published_at' => null]);
        $old = $this->entries->findBySlug('info', 'en', 'legal') ?? self::fail('No entry');
        // A revision from before the page was a preset, with another address and a draft status.
        $this->db->execute("UPDATE entries SET slug = 'old-legal' WHERE id = ?", [$old->id]);
        $draft = $this->entries->find($old->id) ?? self::fail('No entry');
        $this->revisions()->record($draft, null, []);
        $this->db->execute("UPDATE entries SET slug = 'legal' WHERE id = ?", [$old->id]);
        $this->presets()->sync();
        $revision = Cast::int($this->db->scalar("SELECT id FROM entry_revisions WHERE entry_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.slug')) = 'old-legal'", [$old->id]));

        $restored = $this->service()->restore($this->preset('en'), $revision, null, null);

        self::assertSame('legal', $restored->slug);
        self::assertSame(EntryStatus::Published, $restored->status);
        self::assertSame('Old', $restored->title);
    }

    public function testNoOtherEntryOrSectionTakesThePresetAddress(): void
    {
        $info = $this->collections->get('info');
        $sections = $this->collections->taxonomies->get('sections');

        foreach (['en', 'ru'] as $locale) {
            self::assertValidation(['slug' => 'validation.slug.reserved'], fn() => $this->service()->create($info, $locale, new EntryInput('Legal', slug: 'legal'), null, null));
            self::assertValidation(['slug' => 'validation.slug.reserved'], fn() => $this->termService()->create($sections, $locale, new TermInput('Legal', 'legal'), null, null));
        }
        self::assertSame('legal-2', $this->service()->create($info, 'en', new EntryInput('Legal'), null, null)->slug, 'a slug from the title steps aside');
        $this->presets()->sync();
        self::assertSame('legal', $this->preset('en')->slug);
    }

    public function testATranslationOfAPresetIsAPresetAtTheSameAddress(): void
    {
        $this->presets(new Locales('en', ['en']))->sync();

        $translation = $this->service()->createTranslation($this->preset('en'), 'ru', null, null);

        self::assertSame('legal', $translation->slug);
        self::assertSame('legal', $translation->preset);
        self::assertSame('Правовая информация', $translation->title);
        self::assertSame(EntryStatus::Published, $translation->status);
        self::assertSame([], $this->presets()->sync());
    }

    public function testThemesReadThePublicPresetsOfALanguageInOneQuery(): void
    {
        $content = new PublicContent($this->db, $this->entries, $this->collections, $this->clock, new Locales('en', ['en', 'ru']));
        $missing = $content->presets('en');
        self::assertSame([], $missing, 'missing: nothing to link');

        $this->presets(new Locales('en', ['en']))->sync();

        $presets = $content->presets('en');
        self::assertSame(['legal'], array_keys($presets));
        self::assertSame('Legal notice', $presets['legal']->title);
        self::assertSame([], $content->presets('ru'));
        $this->db->execute("UPDATE entries SET published_at = ? WHERE preset = 'legal'", [$this->clock->now()->modify('+1 day')]);
        self::assertSame([], $content->presets('en'), 'not public yet');
    }

    private function presets(?Locales $locales = null): PresetPages
    {
        return new PresetPages($this->collections, $this->entries, $this->service(), $locales ?? new Locales('en', ['en', 'ru']));
    }

    private function preset(string $locale): Entry
    {
        return $this->entries->presetPages('info', 'legal')[$locale] ?? self::fail('No preset in ' . $locale);
    }

    /**
     * @param list<PresetChange> $changes
     *
     * @return list<string>
     */
    private static function lines(array $changes): array
    {
        return array_map(static fn(PresetChange $c): string => $c->action . ' ' . $c->collection . '/' . $c->preset . ' ' . $c->locale, $changes);
    }
}
