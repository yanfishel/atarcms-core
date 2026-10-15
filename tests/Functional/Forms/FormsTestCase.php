<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Forms;

use AtarCms\Http\Security\PublicFormToken;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Paths;
use AtarCms\Kernel\SiteTime;
use AtarCms\Messages\MessageRepository;
use AtarCms\Tests\Functional\FunctionalTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The fixture site with a "booking" form (config forms.booking, set at boot) and the test theme's form.php.
 */
abstract class FormsTestCase extends FunctionalTestCase
{
    protected const array BOOKING = [
        'recipient' => 'bookings@example.com',
        'fields' => [
            'name' => ['type' => 'text'],
            'phone' => ['type' => 'tel', 'required' => true],
            'email' => ['type' => 'email'],
            'service' => ['type' => 'select', 'required' => true, 'options' => ['haircut', 'coloring']],
            'size' => ['type' => 'radio', 'options' => ['short', 'long']],
            'date' => ['type' => 'date', 'required' => true, 'min' => 'today', 'max' => '+60 days'],
            'time' => ['type' => 'time', 'min' => '09:00', 'max' => '18:00'],
            'consent' => ['type' => 'checkbox', 'required' => true],
            'message' => ['type' => 'textarea'],
        ],
    ];

    protected function application(): Application
    {
        return Application::boot(new Paths(static::SITE), ['forms' => ['booking' => self::BOOKING]]);
    }

    /**
     * A valid booking: tomorrow in the site's time zone at 10:30.
     *
     * @return array<string, string>
     */
    protected function booking(): array
    {
        return [
            'name' => 'Dana',
            'phone' => '+972 50 123 4567',
            'service' => 'coloring',
            'date' => $this->day('+1 day'),
            'time' => '10:30',
            'consent' => '1',
        ];
    }

    /**
     * The site's day $modifier from today ("+1 day"), "Y-m-d".
     */
    protected function day(string $modifier = 'now'): string
    {
        $container = $this->app->container();

        return $container->service(SiteTime::class)->today($container->service(Clock::class)->now()->modify($modifier));
    }

    protected function token(string $form = 'booking', string $age = '-1 minute'): string
    {
        $container = $this->app->container();

        return $container->service(PublicFormToken::class)->issue($form, $container->service(Clock::class)->now()->modify($age));
    }

    /**
     * Posts $fields (with a valid token unless $fields has one) as a browser on the site does; $json asks for the
     * modal's JSON answer.
     *
     * @param array<string, mixed> $fields
     * @param array<string, string> $headers
     */
    protected function send(string $path, array $fields, bool $json = false, array $headers = []): ResponseInterface
    {
        return $this->browser->post(
            $path,
            ['_form' => $this->token(), ...$fields],
            ['Origin' => 'https://localhost', 'Sec-Fetch-Site' => 'same-origin', ...($json ? ['Accept' => 'application/json'] : []), ...$headers],
        );
    }

    /**
     * @return array<mixed>
     */
    protected static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : self::fail('A JSON object expected');
    }

    protected function stored(): int
    {
        return $this->app->container()->service(MessageRepository::class)->count();
    }
}
