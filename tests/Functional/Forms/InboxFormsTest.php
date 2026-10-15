<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Forms;

use AtarCms\Messages\MessageRepository;
use DateTimeImmutable;

/**
 * The admin's inbox with more than one form: a filter by form (plain links with unread counts), a form badge per
 * message, a preview from the fields, and a message page with its fields labelled in the form's order.
 */
final class InboxFormsTest extends FormsTestCase
{
    public function testTheInboxFiltersByForm(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $this->contact('Anna', '-3 hours');
        $this->storeBooking('-2 hours', read: true);
        $this->storeBooking('-1 hour');

        $all = (string) $this->browser->get('/admin/messages')->getBody();
        self::assertMatchesRegularExpression('~<nav class="admin-filter-tabs" aria-label="Forms">\s*<a class="admin-filter-tab" href="/admin/messages" aria-current="page">All <span class="admin-tab-count">2</span></a>\s*<a class="admin-filter-tab" href="/admin/messages\?form=contact">Contact form <span class="admin-tab-count">1</span></a>\s*<a class="admin-filter-tab" href="/admin/messages\?form=booking">Book a visit <span class="admin-tab-count">1</span></a>\s*</nav>~', $all);
        self::assertSame(3, substr_count($all, 'class="admin-table-row'));
        self::assertSame(2, substr_count($all, '<span class="admin-chip admin-chip-form">Book a visit</span>'));
        self::assertSame(1, substr_count($all, '<span class="admin-chip admin-chip-form">Contact form</span>'));
        self::assertStringContainsString('<span class="admin-message-preview">coloring · ' . $this->readable($this->day('+1 day')) . ' · 10:30 · Yes</span>', $all, 'options by label (none in the theme: the key), dates for the interface');

        $bookings = (string) $this->browser->get('/admin/messages?form=booking')->getBody();
        self::assertSame(2, substr_count($bookings, 'class="admin-table-row'));
        self::assertStringContainsString('<a class="admin-filter-tab" href="/admin/messages?form=booking" aria-current="page">', $bookings);
        self::assertStringNotContainsString('admin-chip-form', $bookings, 'no badge when filtered by the form');
        self::assertMatchesRegularExpression('~<a class="admin-row-link" href="/admin/messages/\d+">\+972 50 123 4567</a>~', $bookings, 'without a name or e-mail: the phone');

        self::assertSame(3, substr_count((string) $this->browser->get('/admin/messages?form=ghost')->getBody(), 'class="admin-table-row'), 'an unknown form: all');
    }

    public function testAMessageShowsItsFieldsInTheFormsOrder(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $id = $this->storeBooking('-1 hour', extra: ['removed' => 'old value']);

        $page = (string) $this->browser->get('/admin/messages/' . $id)->getBody();

        self::assertStringContainsString('<h1>Message from +972 50 123 4567</h1>', $page);
        self::assertStringContainsString('<dd>Book a visit</dd>', $page);
        preg_match_all('~<tr><th scope="row">([^<]+)</th><td>(.*?)</td></tr>~', $page, $rows, PREG_SET_ORDER);
        self::assertSame([
            ['Phone', '<a href="tel:+972501234567" dir="ltr">+972 50 123 4567</a>'],
            ['service', 'coloring'],
            ['date', $this->readable($this->day('+1 day'))],
            ['time', '10:30'],
            ['consent', 'Yes'],
            ['removed', 'old value'],
        ], array_map(static fn(array $row): array => [$row[1], $row[2]], $rows));
        self::assertStringContainsString('href="tel:+972501234567"', $page);
        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-secondary" href="tel:\+972501234567">.*?Call</a>~', $page);
        self::assertStringNotContainsString('mailto:', $page, 'no e-mail, no Reply');
    }

    public function testAMessageOfARemovedFormStillOpens(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $id = $this->app->container()->service(MessageRepository::class)->insert([
            'form' => 'survey',
            'locale' => 'en',
            'email' => 'old@example.com',
            'fields' => ['answer' => 'yes'],
            'page_url' => 'https://localhost/',
            'ip_hash' => str_repeat('a', 64),
            'created_at' => new DateTimeImmutable('-1 day'),
        ]);

        $response = $this->browser->get('/admin/messages/' . $id);
        $page = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<dd>survey</dd>', $page);
        self::assertStringContainsString('<tr><th scope="row">answer</th><td>yes</td></tr>', $page);
        self::assertStringContainsString('href="mailto:old%40example.com?subject=', $page);
        self::assertStringContainsString('<span class="admin-chip admin-chip-form">survey</span>', (string) $this->browser->get('/admin/messages')->getBody());
    }

    private function readable(string $date): string
    {
        return new DateTimeImmutable($date)->format('M j, Y');
    }

    private function contact(string $name, string $age): int
    {
        return $this->app->container()->service(MessageRepository::class)->insert([
            'form' => 'contact',
            'locale' => 'en',
            'name' => $name,
            'email' => strtolower($name) . '@example.com',
            'body' => 'Hello there, friend.',
            'page_url' => 'https://localhost/contacts',
            'ip_hash' => str_repeat('a', 64),
            'created_at' => new DateTimeImmutable($age),
        ]);
    }

    /**
     * @param array<string, string> $extra
     */
    private function storeBooking(string $age, bool $read = false, array $extra = []): int
    {
        return $this->app->container()->service(MessageRepository::class)->insert([
            'form' => 'booking',
            'locale' => 'en',
            'phone' => '+972 50 123 4567',
            'fields' => ['service' => 'coloring', 'date' => $this->day('+1 day'), 'time' => '10:30', 'consent' => '1', ...$extra],
            'page_url' => 'https://localhost/forms/booking',
            'ip_hash' => str_repeat('a', 64),
            'created_at' => new DateTimeImmutable($age),
            'read_at' => $read ? new DateTimeImmutable() : null,
        ]);
    }
}
