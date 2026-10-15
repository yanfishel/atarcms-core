<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\Content\EntryForm;
use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Seo;
use AtarCms\Content\Term;
use AtarCms\Kernel\SiteTime;
use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class EntryFormTest extends TestCase
{
    private Collection $pages;

    private SiteTime $utc;

    protected function setUp(): void
    {
        $this->pages = Collection::make('pages')->hierarchical()->fields([Field::text('summary'), Field::number('rating')]);
        $this->utc = new SiteTime(new DateTimeZone('UTC'));
    }

    public function testParsesAValidForm(): void
    {
        $data = EntryForm::parse($this->post([
            'title' => 'Hello',
            'slug' => 'hello',
            'status' => 'scheduled',
            'published_at' => '2026-10-01T10:30',
            'parent_id' => '5',
            'position' => '-3',
            'meta_title' => 'Meta',
            'meta_description' => 'Desc',
            'fields' => ['summary' => 'Sum', 'rating' => '4', 'ghost' => 'dropped'],
            'version' => '2026-09-28 12:00:00',
            'locale' => 'ru',
        ]), $this->pages, $this->utc);

        self::assertSame([], $data->errors);
        self::assertSame('Hello', $data->input->title);
        self::assertSame('hello', $data->input->slug);
        self::assertSame(EntryStatus::Scheduled, $data->input->status);
        self::assertSame('2026-10-01 10:30:00 UTC', $data->input->publishedAt?->format('Y-m-d H:i:s T'));
        self::assertSame(5, $data->input->parentId);
        self::assertSame(-3, $data->input->position);
        self::assertSame(['summary' => 'Sum', 'rating' => '4'], $data->input->fields);
        self::assertSame(['Meta', 'Desc'], [$data->input->metaTitle, $data->input->metaDescription]);
        self::assertSame('2026-09-28 12:00:00', $data->version);
        self::assertSame('ru', $data->locale);
        self::assertSame('2026-10-01T10:30', $data->values['published_at']);
        self::assertSame(['summary' => 'Sum', 'rating' => '4'], $data->values['fields']);
    }

    public function testMalformedCoreValuesBecomeErrors(): void
    {
        $data = EntryForm::parse($this->post([
            'title' => 'X',
            'status' => 'bogus',
            'published_at' => '2026-13-01T10:00',
            'parent_id' => 'abc',
            'position' => '99999999999',
        ]), $this->pages, $this->utc);

        self::assertSame([
            'status' => 'validation.status.invalid',
            'published_at' => 'validation.field.datetime',
            'position' => 'validation.field.number',
            'parent_id' => 'validation.parent.invalid',
        ], $data->errors);
        self::assertSame(EntryStatus::Draft, $data->input->status);
        self::assertNull($data->input->publishedAt);
        self::assertSame('bogus', $data->values['status']);
    }

    public function testPositionAtTheInt32Boundary(): void
    {
        $atMin = EntryForm::parse($this->post(['title' => 'X', 'position' => '-2147483648']), $this->pages, $this->utc);
        self::assertSame([], $atMin->errors);
        self::assertSame(-2147483648, $atMin->input->position);
        self::assertSame(EntryService::MIN_POSITION, $atMin->input->position);

        $aboveMax = EntryForm::parse($this->post(['title' => 'X', 'position' => '2147483648']), $this->pages, $this->utc);
        self::assertSame(['position' => 'validation.field.number'], $aboveMax->errors);
    }

    public function testEmptyAndMissingValues(): void
    {
        $data = EntryForm::parse((new Psr17Factory())->createServerRequest('POST', '/x'), $this->pages, $this->utc);

        self::assertSame([], $data->errors);
        self::assertSame('', $data->input->title);
        self::assertNull($data->input->parentId);
        self::assertSame(0, $data->input->position);
        self::assertSame([], $data->input->fields);
        self::assertSame(EntryForm::defaults(), $data->values);
    }

    public function testThePublicationDateIsReadAndShownInTheSiteZone(): void
    {
        $berlin = new SiteTime(new DateTimeZone('Europe/Berlin'));

        $data = EntryForm::parse($this->post(['title' => 'X', 'published_at' => '2026-10-01T10:30']), $this->pages, $berlin);
        self::assertSame('2026-10-01 08:30:00 UTC', $data->input->publishedAt?->format('Y-m-d H:i:s e'));
        self::assertSame(['published_at' => 'validation.field.datetime'], EntryForm::parse($this->post(['title' => 'X', 'published_at' => '2026-03-29T02:30']), $this->pages, $berlin)->errors);

        $now = new DateTimeImmutable('2026-10-01 08:30:00', new DateTimeZone('UTC'));
        $entry = new Entry(7, $this->pages, 'en', str_repeat('a', 32), 'x', 'X', EntryStatus::Scheduled, $now, null, 0, null, $now, $now, new Seo(), []);
        self::assertSame('2026-10-01T10:30', EntryForm::values($entry, $berlin)['published_at']);
    }

    public function testValuesOfAStoredEntry(): void
    {
        $now = new DateTimeImmutable('2026-09-28 12:34:56');
        $entry = new Entry(7, $this->pages, 'en', str_repeat('a', 32), 'hello', 'Hello', EntryStatus::Published, $now, 3, 2, null, $now, $now, new Seo('Meta'), ['rating' => 4]);

        self::assertSame([
            'title' => 'Hello',
            'slug' => 'hello',
            'status' => 'published',
            'published_at' => '2026-09-28T12:34',
            'parent_id' => '3',
            'position' => '2',
            'meta_title' => 'Meta',
            'meta_description' => '',
            'fields' => ['rating' => 4],
            'terms' => [],
        ], EntryForm::values($entry, $this->utc));
    }

    public function testParsesTermPickersAndTags(): void
    {
        $articles = Collection::make('articles')->taxonomies(['topics'])->fields([Field::tags('tags')]);

        $data = EntryForm::parse($this->post([
            'title' => 'X',
            'terms' => ['topics' => ['3', '5'], 'regions' => ['9']],
            'fields' => ['tags' => 'PHP,  web  dev , php,'],
        ]), $articles, $this->utc);

        self::assertSame([], $data->errors);
        self::assertSame(['topics' => [3, 5], 'tags' => ['PHP', 'web dev']], $data->input->terms);
        self::assertSame(['topics' => ['3', '5']], $data->values['terms']);
        self::assertSame(['tags' => 'PHP,  web  dev , php,'], $data->values['fields']);

        $none = EntryForm::parse($this->post(['title' => 'X']), $articles, $this->utc);
        self::assertSame(['topics' => [], 'tags' => []], $none->input->terms);
        self::assertSame(['topics' => []], $none->values['terms']);

        $bad = EntryForm::parse($this->post(['title' => 'X', 'terms' => ['topics' => ['abc', ['x'], '7']]]), $articles, $this->utc);
        self::assertSame(['terms.topics' => 'validation.terms.invalid'], $bad->errors);
        self::assertSame(['topics' => [7], 'tags' => []], $bad->input->terms);
    }

    public function testValuesIncludeTheEntrysTerms(): void
    {
        $articles = Collection::make('articles')->taxonomies(['topics'])->fields([Field::tags('tags')]);
        $now = new DateTimeImmutable('2026-09-28 12:00:00');
        $entry = new Entry(7, $articles, 'en', str_repeat('a', 32), 'hello', 'Hello', EntryStatus::Draft, null, null, 0, null, $now, $now, new Seo(), []);

        $values = EntryForm::values($entry, $this->utc, [
            new Term(3, 'topics', 'en', str_repeat('b', 32), 'php', 'PHP', null, 0, $now, $now),
            new Term(8, 'tags', 'en', str_repeat('c', 32), 'news', 'News', null, 0, $now, $now),
            new Term(9, 'tags', 'en', str_repeat('d', 32), 'web', 'Web', null, 0, $now, $now),
        ]);

        self::assertSame(['topics' => ['3']], $values['terms']);
        self::assertSame(['tags' => 'News, Web'], $values['fields']);
        self::assertSame(['topics' => []], EntryForm::values($entry, $this->utc)['terms']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', '/x')->withParsedBody($body);
    }
}
