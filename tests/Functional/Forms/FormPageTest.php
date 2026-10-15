<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Forms;

/**
 * GET /forms/{key}: the page of a configured form, the target of a dialog's trigger link and the path without
 * JavaScript.
 */
final class FormPageTest extends FormsTestCase
{
    public function testTheFormPageShowsEveryFieldAndIsNotIndexed(): void
    {
        $response = $this->browser->get('/forms/booking');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'), 'a visitor of the form page gets no cookie');
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('<meta name="robots" content="noindex">', $body);
        self::assertStringNotContainsString('rel="canonical"', $body);
        self::assertStringContainsString('<h1>Book a visit</h1>', $body);
        self::assertStringContainsString('<form method="post" action="/forms/booking">', $body);
        self::assertMatchesRegularExpression('~<input type="hidden" name="_form" value="\d+\.[A-Za-z0-9_-]{43}">~', $body);
        foreach (['name', 'phone', 'email', 'service', 'size', 'date', 'time', 'consent', 'message'] as $field) {
            self::assertStringContainsString('data-field="booking-' . $field . '"', $body, $field);
        }
        self::assertStringContainsString('data-field="booking-date" data-type="date" data-required="1" data-min="' . $this->day() . '" data-max="' . $this->day('+60 days') . '"', $body, 'the bounds for the site\'s today');
        self::assertStringContainsString('data-field="booking-time" data-type="time" data-required="0" data-min="09:00" data-max="18:00"', $body);
        self::assertStringContainsString('data-options="haircut coloring"', $body);
        self::assertStringContainsString('name="website"', $body);
    }

    public function testTheFormPageSpeaksTheRequestsLanguage(): void
    {
        $body = (string) $this->browser->get('/ru/forms/booking')->getBody();

        self::assertStringContainsString('<h1>Записаться</h1>', $body);
        self::assertStringContainsString('action="/ru/forms/booking"', $body);
    }

    public function testASentFormShowsTheThanks(): void
    {
        $body = (string) $this->browser->get('/forms/booking?sent=1')->getBody();

        self::assertStringContainsString('<p class="sent" role="status">Thank you! We will call you back.</p>', $body);
        self::assertStringNotContainsString('<form method="post"', $body);
    }

    public function testOnlyConfiguredFormsHaveAPage(): void
    {
        self::assertSame(404, $this->browser->get('/forms/nope')->getStatusCode());
        self::assertSame(404, $this->browser->get('/forms/contact')->getStatusCode(), 'the contact form lives on its page');
    }
}
