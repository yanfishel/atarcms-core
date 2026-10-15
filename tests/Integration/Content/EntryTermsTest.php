<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Auth\AuditLog;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryTerms;
use AtarCms\Content\Field\Field;
use AtarCms\Content\ReservedSlugs;
use AtarCms\Content\SlugHistoryRepository;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermService;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use AtarCms\Tests\Support\Cast;

final class EntryTermsTest extends ContentTestCase
{
    public function testCreateStoresPickedTermsAndCreatesMissingTags(): void
    {
        $php = $this->insertTerm();
        $news = $this->insertTerm(['taxonomy' => 'tags', 'slug' => 'news', 'name' => 'News']);

        $entry = $this->service()->create($this->collections->get('articles'), 'en', new EntryInput('Hello', terms: [
            'topics' => [$php],
            'tags' => ['news', 'Brand new'],
        ]), null, null);

        $created = $this->terms->findBySlug('tags', 'en', 'brand-new');
        self::assertNotNull($created);
        self::assertSame(['News', 'Brand new', 'PHP'], self::names($this->terms->forEntries([$entry->id])[$entry->id] ?? []));
        self::assertSame(['featured' => false], $entry->data);
        self::assertSame(['term.created', 'entry.created'], $this->auditActions());
        self::assertSame([$news, $created->id, $php], $this->revisions()->forEntry($entry->id)[0]->snapshot['terms'] ?? null);
    }

    public function testTamperedChoicesAreRejectedAndNothingIsWritten(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');
        $russian = $this->insertTerm(['locale' => 'ru']);
        $tag = $this->insertTerm(['taxonomy' => 'tags', 'slug' => 'news', 'name' => 'News']);
        $region = $this->insertTerm(['taxonomy' => 'regions', 'slug' => 'north', 'name' => 'North']);

        foreach ([[$russian], [$tag], [$region], [99999], ['PHP']] as $choice) {
            self::assertValidation(
                ['terms.topics' => 'validation.terms.invalid'],
                fn() => $service->create($articles, 'en', new EntryInput('Tampered', terms: ['topics' => $choice]), null, null),
            );
        }
        self::assertValidation(
            ['terms.regions' => 'validation.terms.invalid'],
            fn() => $service->create($articles, 'en', new EntryInput('Tampered', terms: ['regions' => [$region]]), null, null),
        );
        self::assertValidation(
            ['tags' => 'validation.terms.invalid'],
            fn() => $service->create($articles, 'en', new EntryInput('Tampered', terms: ['tags' => [$russian]]), null, null),
        );
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entry_terms')));
        self::assertSame(3, Cast::int($this->db->scalar('SELECT COUNT(*) FROM terms')));
    }

    public function testTagLimitsAndNonTranslatableCollections(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');

        self::assertValidation(
            ['tags' => 'validation.field.too_many'],
            fn() => $service->create($articles, 'en', new EntryInput('Many', terms: ['tags' => ['a', 'b', 'c', 'd', 'e', 'f']]), null, null),
            ['tags' => ['max' => 5]],
        );
        self::assertValidation(
            ['tags' => 'validation.field.too_long'],
            fn() => $service->create($articles, 'en', new EntryInput('Long', terms: ['tags' => [str_repeat('x', 201)]]), null, null),
            ['tags' => ['max' => 200]],
        );

        $north = $this->insertTerm(['taxonomy' => 'regions', 'slug' => 'north', 'name' => 'North']);
        $nord = $this->insertTerm(['taxonomy' => 'regions', 'slug' => 'north', 'name' => 'Север', 'locale' => 'ru']);
        $event = $service->create($this->collections->get('events'), 'en', new EntryInput('Concert', terms: ['regions' => [$north]]), null, null);

        self::assertSame([$north], $this->terms->idsForEntry($event->id));
        self::assertValidation(
            ['terms.regions' => 'validation.terms.invalid'],
            fn() => $service->update($event, new EntryInput('Concert', terms: ['regions' => [$nord]]), null, null),
        );
    }

    public function testUpdateReplacesOnlyTheSubmittedTaxonomies(): void
    {
        $service = $this->service();
        $php = $this->insertTerm();
        $go = $this->insertTerm(['slug' => 'go', 'name' => 'Go']);
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('Hello', terms: ['topics' => [$php], 'tags' => ['News']]), null, null);

        $entry = $service->update($entry, new EntryInput('Hello', terms: ['topics' => [$go]]), null, null);
        self::assertSame(['News', 'Go'], self::names($this->terms->forEntries([$entry->id])[$entry->id] ?? []));

        $entry = $service->update($entry, EntryInput::fromEntry($entry), null, null);
        self::assertSame(['News', 'Go'], self::names($this->terms->forEntries([$entry->id])[$entry->id] ?? []));

        $service->update($entry, new EntryInput('Hello', terms: ['topics' => [], 'tags' => []]), null, null);
        self::assertSame([], $this->terms->idsForEntry($entry->id));
    }

    public function testRestoreBringsBackTermsAndDropsDeletedOnes(): void
    {
        $service = $this->service();
        $php = $this->insertTerm();
        $go = $this->insertTerm(['slug' => 'go', 'name' => 'Go']);
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('Hello', terms: ['topics' => [$php, $go], 'tags' => ['News']]), null, null);
        $first = $this->revisions()->forEntry($entry->id)[0]->id;
        $entry = $service->update($entry, new EntryInput('Hello', terms: ['topics' => [], 'tags' => []]), null, null);
        $this->terms->delete($go);

        $entry = $service->restore($entry, $first, null, null);
        self::assertSame(['News', 'PHP'], self::names($this->terms->forEntries([$entry->id])[$entry->id] ?? []));

        // A revision written before Plan 4c has no "terms": restoring it keeps the current terms.
        $this->db->execute('UPDATE entry_revisions SET data = JSON_REMOVE(data, \'$.terms\') WHERE id = ?', [$first]);
        $entry = $service->update($entry, new EntryInput('Hello', terms: ['topics' => []]), null, null);
        $service->restore($entry, $first, null, null);
        self::assertSame(['News'], self::names($this->terms->forEntries([$entry->id])[$entry->id] ?? []));
    }

    public function testTranslationsCarryTheTranslatedTerms(): void
    {
        $group = str_repeat('b', 32);
        $php = $this->insertTerm(['translation_group' => $group]);
        $phpRu = $this->insertTerm(['locale' => 'ru', 'translation_group' => $group, 'name' => 'ПХП']);
        $go = $this->insertTerm(['slug' => 'go', 'name' => 'Go']);
        $service = $this->service();
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('Hello', terms: ['topics' => [$php, $go], 'tags' => ['News']]), null, null);

        $translation = $service->createTranslation($entry, 'ru', null, null);

        self::assertSame([$phpRu], $this->terms->idsForEntry($translation->id));
        self::assertSame([$phpRu], $this->revisions()->forEntry($translation->id)[0]->snapshot['terms'] ?? null);
    }

    /**
     * A non-array value for a taxonomy (e.g. a crafted request body) must be a 422 validation error, not a
     * TypeError 500 from foreach()/mb_strlen() choking on it.
     */
    public function testNonArrayTaxonomyValueIsRejectedAsInvalidInsteadOfCrashing(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');

        self::assertValidation(
            ['terms.topics' => 'validation.terms.invalid'],
            fn() => $service->create($articles, 'en', new EntryInput('Bad', terms: ['topics' => 'not-an-array']), null, null), // @phpstan-ignore argument.type
        );
        self::assertValidation(
            ['tags' => 'validation.terms.invalid'],
            fn() => $service->create($articles, 'en', new EntryInput('Bad', terms: ['tags' => 'not-an-array']), null, null), // @phpstan-ignore argument.type
        );
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
    }

    /**
     * A non-int, non-string item (e.g. an array or bool sneaked into the list) is invalid rather than
     * crashing mb_strlen()/is_int() checks.
     */
    public function testNonIntNonStringTermItemsAreRejectedAsInvalid(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');

        self::assertValidation(
            ['terms.topics' => 'validation.terms.invalid'],
            fn() => $service->create($articles, 'en', new EntryInput('Bad', terms: ['topics' => [['nested']]]), null, null), // @phpstan-ignore argument.type
        );
        self::assertValidation(
            ['tags' => 'validation.terms.invalid'],
            fn() => $service->create($articles, 'en', new EntryInput('Bad', terms: ['tags' => [true]]), null, null), // @phpstan-ignore argument.type
        );
    }

    /**
     * Tag names go through TagsType::names() before the checks: whitespace is collapsed and trimmed, blanks
     * are dropped, case-insensitive duplicates are removed and a name containing a comma is split into
     * separate tags — exactly as the admin form (which posts one comma-separated string) would treat it.
     */
    public function testTagNamesAreNormalizedThroughTagsTypeNames(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');

        $entry = $service->create($articles, 'en', new EntryInput('Hello', terms: [
            'tags' => ['  News, Sport  ', '', '  ', 'news', 'Extra'],
        ]), null, null);

        self::assertSame(
            ['News', 'Sport', 'Extra'],
            array_map(static fn(Term $t): string => $t->name, $this->terms->forEntries([$entry->id])[$entry->id] ?? []),
        );
        self::assertNull($this->terms->findBySlug('tags', 'en', 'news-sport'), 'the comma name was split, not stored whole');
    }

    public function testUpdateRejectsNewUnknownRelationIds(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');
        $kept = $this->insertEntry(['slug' => 'kept']);
        $page = $this->insertEntry(['collection' => 'pages', 'slug' => 'page']);
        $entry = $service->create($articles, 'en', new EntryInput('Related', fields: ['related' => [$kept]]), null, null);

        self::assertValidation(
            ['related' => 'validation.field.relation'],
            fn() => $service->update($entry, new EntryInput('Related', fields: ['related' => [$kept, 99999]]), null, null),
        );
        self::assertValidation(
            ['related' => 'validation.field.relation'],
            fn() => $service->update($entry, new EntryInput('Related', fields: ['related' => [$page]]), null, null),
        );
        self::assertSame([$kept], $service->update($entry, new EntryInput('Related', fields: ['related' => [$kept]]), null, null)->field('related'));
    }

    /**
     * TermService::tagIds() must run inside EntryService's already-open transaction without starting a nested
     * one: two brand-new tag names are created while the entry itself is being inserted, in one create() call.
     */
    public function testTagIdsCreatesNewTagsInsideEntryServiceTransactionWithoutNestedTransactionError(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');

        $entry = $service->create($articles, 'en', new EntryInput('Hello', terms: ['tags' => ['Alpha', 'Beta']]), null, null);

        self::assertSame(['Alpha', 'Beta'], self::names($this->terms->forEntries([$entry->id])[$entry->id] ?? []));
        self::assertSame(['term.created', 'term.created', 'entry.created'], $this->auditActions());
        self::assertSame(2, Cast::int($this->db->scalar('SELECT COUNT(*) FROM terms')));
    }

    /**
     * Controller ruling: on create(), a collection's required tags field must be enforced even when the caller's
     * $input->terms omits that taxonomy's key entirely (not just when $input->terms itself is null) — otherwise a
     * direct service call bypasses required() by never mentioning the tags taxonomy.
     */
    public function testMissingTagsKeyOnCreateCountsAsEmptyAndTriggersRequired(): void
    {
        $taxonomies = new Taxonomies([Taxonomy::make('topics')->hierarchical(), Taxonomy::make('tags')]);
        $collections = new Collections(
            [Collection::make('reviews')->translatable()->taxonomies(['topics'])->fields([Field::tags('tags')->required()])],
            $taxonomies,
        );
        $terms = new TermRepository($this->db);
        $entries = new EntryRepository($this->db, $collections);
        $locales = new Locales('en', ['en', 'ru']);
        $termService = new TermService($this->db, $terms, $taxonomies, $locales, new AuditLog($this->db, $this->clock), $this->clock, new ReservedSlugs(new Router(), $locales), $collections, new SlugHistoryRepository($this->db));
        $entryTerms = new EntryTerms($terms, $termService, $taxonomies);
        $service = new EntryService(
            $this->db,
            $entries,
            $this->revisions(),
            $locales,
            new AuditLog($this->db, $this->clock),
            $this->clock,
            new ReservedSlugs(new Router(), $locales),
            $entryTerms,
            $this->entryMedia(),
            $this->entryAccess(),
            $collections,
            $this->searchIndexer($collections),
            $this->richTextTheme(),
            $this->translator(),
            $this->shareImageService(),
            new SlugHistoryRepository($this->db),
        );
        $reviews = $collections->get('reviews');

        // $input->terms is not null, but its "tags" key is missing entirely: still required.
        self::assertValidation(
            ['tags' => 'validation.field.required'],
            fn() => $service->create($reviews, 'en', new EntryInput('Good', terms: ['topics' => []]), null, null),
        );
        // $input->terms is null: also required (the baseline case).
        self::assertValidation(
            ['tags' => 'validation.field.required'],
            fn() => $service->create($reviews, 'en', new EntryInput('Good'), null, null),
        );
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
    }

    /**
     * @param list<Term> $terms
     *
     * @return list<string>
     */
    private static function names(array $terms): array
    {
        return array_map(static fn(Term $term): string => $term->name, $terms);
    }
}
