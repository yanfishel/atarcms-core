<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Forms;

use AtarCms\Http\Security\PublicFormToken;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\SiteTime;
use AtarCms\Messages\MessageRepository;
use AtarCms\Tests\Functional\Skeleton\SkeletonTestCase;
use AtarCms\Tests\Support\Regex;

/**
 * The skeleton's booking form (config/forms.php): a "Book" link in the header opens it in a dialog on every page
 * (forms.js), its page /forms/booking works without JavaScript, and the contacts page holds both forms with unique ids.
 */
final class SkeletonFormsTest extends SkeletonTestCase
{
    private const array FIELDS = ['name', 'phone', 'email', 'service', 'date', 'time', 'consent', 'message'];

    public function testEveryPageOffersTheBookingDialog(): void
    {
        $body = (string) $this->browser->get('/')->getBody();

        self::assertMatchesRegularExpression('~<a class="[^"]*" href="/forms/booking" data-form-dialog="form-booking">~', $body);
        self::assertStringContainsString('<dialog class="form-dialog" id="form-booking" aria-labelledby="form-booking-title"', $body);
        self::assertStringContainsString('<form method="dialog">', $body, 'the close button works without JavaScript');
        self::assertStringContainsString('<form class="form form-dialog-form" method="post" action="/forms/booking" novalidate>', $body);
        foreach (self::FIELDS as $field) {
            self::assertStringContainsString('id="booking-' . $field . '-error"', $body, $field);
        }
        self::assertStringContainsString('<p class="form-errors" role="alert" data-form-error></p>', $body);
        self::assertStringContainsString('<p class="notice" role="status" data-form-status></p>', $body);
        self::assertMatchesRegularExpression('~<script type="module" src="/theme-assets/js/forms\.js\?v=[0-9a-f]+"></script>~', $body);
        self::assertSame(1, substr_count($body, 'js/forms.js'));
        self::assertSame([], $this->duplicateIds($body));
        self::assertSame([], $this->browser->get('/')->getHeader('Set-Cookie'));
    }

    public function testTheContactsPageHoldsBothFormsWithUniqueIds(): void
    {
        $body = (string) $this->browser->get('/contacts')->getBody();

        self::assertStringContainsString('action="/contacts#contact"', $body);
        self::assertStringContainsString('action="/forms/booking"', $body);
        self::assertStringContainsString('id="contact-email"', $body);
        self::assertStringContainsString('id="booking-phone"', $body);
        self::assertSame([], $this->duplicateIds($body));
    }

    public function testTheFormPageWorksWithoutJavaScript(): void
    {
        $container = $this->app->container();
        $today = $container->service(SiteTime::class)->today($container->service(Clock::class)->now());
        $body = (string) $this->browser->get('/forms/booking')->getBody();

        self::assertStringContainsString('<meta name="robots" content="noindex">', $body);
        self::assertStringContainsString('<h1>Book a consultation</h1>', $body);
        self::assertStringContainsString('<form class="form" method="post" action="/forms/booking" novalidate>', $body);
        self::assertMatchesRegularExpression('~<input type="date" id="booking-date" name="date" value="" min="' . $today . '" max="\d{4}-\d{2}-\d{2}" required~', $body);
        self::assertStringContainsString('<input type="time" id="booking-time" name="time" value="" min="09:00" max="18:00"', $body);
        self::assertStringContainsString('<option value="diagnostics">Diagnostics</option>', $body);
        self::assertStringContainsString('<input type="checkbox" id="booking-consent" name="consent" value="1" required', $body);
        self::assertStringNotContainsString('id="form-booking"', $body, 'no dialog of the same form on its own page');
        self::assertSame([], $this->duplicateIds($body));

        $he = (string) $this->browser->get('/he/forms/booking')->getBody();
        self::assertStringContainsString('<html lang="he" dir="rtl">', $he);
        self::assertStringContainsString('action="/he/forms/booking"', $he);
    }

    public function testABookingWithoutJavaScriptIsSentAndThanked(): void
    {
        $container = $this->app->container();
        $tomorrow = $container->service(SiteTime::class)->today($container->service(Clock::class)->now()->modify('+1 day'));
        $token = $container->service(PublicFormToken::class)->issue('booking', $container->service(Clock::class)->now()->modify('-1 minute'));

        $refused = $this->browser->post('/forms/booking', ['_form' => $token, 'name' => 'Dana', 'phone' => '', 'service' => 'diagnostics', 'date' => $tomorrow, 'consent' => '1'], ['Origin' => 'https://localhost', 'Sec-Fetch-Site' => 'same-origin']);
        self::assertSame(422, $refused->getStatusCode());
        $body = (string) $refused->getBody();
        self::assertStringContainsString('<div class="form-errors" role="alert">', $body);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="booking-phone-error"', $body);
        self::assertStringContainsString('<option value="diagnostics" selected>', $body);
        $again = Regex::match('~name="_form" value="([^"]+)"~', $body)[1];
        // The refused form's new token may be sent again at once: the visitor is no bot.

        $sent = $this->browser->post('/forms/booking', ['_form' => $again, 'name' => 'Dana', 'phone' => '+972 50 123 4567', 'service' => 'diagnostics', 'date' => $tomorrow, 'consent' => '1'], ['Origin' => 'https://localhost', 'Sec-Fetch-Site' => 'same-origin']);
        self::assertSame(303, $sent->getStatusCode());
        self::assertSame(1, $container->service(MessageRepository::class)->count('booking'));
        $thanks = (string) $this->browser->get((string) $sent->getHeaderLine('Location'))->getBody();
        self::assertStringContainsString('<p class="notice" role="status">', $thanks);
    }

    /**
     * @return list<string>
     */
    private function duplicateIds(string $html): array
    {
        preg_match_all('/\sid="([^"]+)"/', $html, $ids);
        $counts = array_count_values($ids[1]);

        return array_map(strval(...), array_keys(array_filter($counts, static fn(int $count): bool => $count > 1)));
    }
}
