<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Kernel\Container;
use AtarCms\Kernel\SiteTime;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use DateTimeZone;

/**
 * With app.timezone set, the admin shows and takes times in the site zone; the database keeps UTC.
 */
final class SiteTimeZoneTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(SiteTime::class, new SiteTime(new DateTimeZone('Europe/Berlin')));
    }

    public function testAdminShowsTimesInTheSiteZone(): void
    {
        $entry = $this->entry('articles', 'Coming soon', ['status' => EntryStatus::Scheduled, 'publishedAt' => $this->clock->now()->modify('+1 day')]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $dashboard = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('2026-09-29 14:00', $dashboard);
        self::assertStringNotContainsString('(UTC)', $dashboard);
        self::assertStringContainsString('<td data-label="Published"><span class="admin-table-date">Sep 29, 2026</span><span class="admin-table-time">14:00</span></td>', (string) $this->browser->get('/admin/content/articles')->getBody());

        $form = (string) $this->browser->get('/admin/content/articles/' . $entry->id)->getBody();
        self::assertStringContainsString('Publication date (Europe/Berlin)', $form);
        self::assertStringContainsString('name="published_at" value="2026-09-29T14:00" data-now="2026-09-28T14:00"', $form, 'the frozen clock (12:00 UTC) in the site zone');
    }

    public function testThemesFormatDatesInTheSiteZone(): void
    {
        $this->entry('articles', 'Out now', ['slug' => 'out-now', 'publishedAt' => $this->clock->now()->modify('-1 hour')]);

        self::assertStringContainsString('<p class="date">2026-09-28 13:00</p>', (string) $this->browser->get('/articles/out-now')->getBody());
    }

    public function testThePublicationDateIsEnteredInTheSiteZoneAndStoredInUtc(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $saved = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Autumn',
            'status' => 'scheduled',
            'published_at' => '2026-10-05T09:00',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        $stored = $this->app->container()->service(EntryRepository::class)->findBySlug('articles', 'en', 'autumn');
        self::assertSame('2026-10-05 07:00:00', $stored?->publishedAt?->format('Y-m-d H:i:s'));

        // 02:30 does not exist in Berlin on the day the clocks spring forward.
        $gap = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Spring',
            'status' => 'scheduled',
            'published_at' => '2027-03-28T02:30',
        ]);
        self::assertSame(422, $gap->getStatusCode());
        self::assertStringContainsString('Enter a valid date and time.', (string) $gap->getBody());
    }
}
