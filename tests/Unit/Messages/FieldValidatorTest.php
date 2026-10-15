<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Messages;

use AtarCms\Content\ValidationErrors;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\SiteTime;
use AtarCms\Messages\Form\FieldValidator;
use AtarCms\Messages\Form\FormDefinition;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormInput;
use AtarCms\Tests\Support\FrozenClock;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class FieldValidatorTest extends TestCase
{
    private FormDefinitions $forms;

    protected function setUp(): void
    {
        $this->forms = FormDefinitions::fromConfig(new Config(['forms' => ['booking' => ['fields' => [
            'name' => ['type' => 'text'],
            'phone' => ['type' => 'tel', 'required' => true],
            'email' => ['type' => 'email'],
            'company' => ['type' => 'text'],
            'fax' => ['type' => 'tel'],
            'service' => ['type' => 'select', 'required' => true, 'options' => ['haircut', 'coloring']],
            'size' => ['type' => 'radio', 'options' => ['s', 'm']],
            'date' => ['type' => 'date', 'required' => true, 'min' => 'today', 'max' => '+60 days'],
            'fixed' => ['type' => 'date', 'min' => '2026-11-01', 'max' => '2026-11-30'],
            'time' => ['type' => 'time', 'min' => '09:00', 'max' => '18:00'],
            'consent' => ['type' => 'checkbox', 'required' => true],
            'news' => ['type' => 'checkbox'],
            'message' => ['type' => 'textarea'],
        ]]]]));
    }

    public function testAValidBookingIsCleanedAndKeptInConfigOrder(): void
    {
        $values = $this->validate([
            'message' => "  Line one\r\nLine\x00 two  ",
            'name' => " Anna\x07\n Petrova ",
            'phone' => '+972 50 123-4567',
            'service' => 'coloring',
            'date' => '2026-10-15',
            'time' => '09:00',
            'consent' => 'on',
            'news' => '',
            'company' => '',
        ]);

        self::assertSame([
            'name' => 'Anna Petrova',
            'phone' => '+972 50 123-4567',
            'service' => 'coloring',
            'date' => '2026-10-15',
            'time' => '09:00',
            'consent' => '1',
            'message' => "Line one\nLine two",
        ], $values, 'empty optional fields are left out; a checked box is "1"');
    }

    public function testEachTypeRefusesWhatItCannotTake(): void
    {
        $this->assertRefused([
            'phone' => 'validation.field.required',
            'service' => 'validation.field.required',
            'date' => 'validation.field.required',
            'consent' => 'validation.form.checkbox',
        ], []);

        $this->assertRefused([
            'name' => 'validation.field.too_long',
            'email' => 'validation.email.invalid',
            'company' => 'validation.field.too_long',
            'fax' => 'validation.field.too_long',
            'phone' => 'validation.message.phone',
            'service' => 'validation.form.option',
            'size' => 'validation.form.option',
            'date' => 'validation.form.date',
            'fixed' => 'validation.form.date',
            'time' => 'validation.form.time',
            'message' => 'validation.field.too_long',
        ], [
            'name' => str_repeat('a', 101),
            'email' => 'anna@',
            'company' => str_repeat('a', 201),
            'fax' => str_repeat('1', 201),
            'phone' => 'call me',
            'service' => 'massage',
            'size' => 'xl',
            'date' => '2026-02-30',
            'fixed' => '15.11.2026',
            'time' => '25:00',
            'consent' => '1',
            'message' => str_repeat('a', 5001),
        ]);
    }

    public function testAnArrayInsteadOfAStringIsAnEmptyField(): void
    {
        $input = FormInput::fromBody($this->booking(), ['service' => ['haircut'], 'phone' => '+972 50 123 4567', 'date' => '2026-10-20', 'consent' => '1', 'ghost' => 'boo']);

        self::assertSame('', $input->values['service']);
        self::assertArrayNotHasKey('ghost', $input->values, 'only configured fields are read');
        $this->assertRefused(['service' => 'validation.field.required'], ['service' => ['haircut'], 'phone' => '+972 50 123 4567', 'date' => '2026-10-20', 'consent' => '1']);
    }

    /**
     * The site's "today" is the day in app.timezone: at 22:30 UTC on October 14 it is already October 15 in
     * Jerusalem, so "min: today" refuses the 14th and takes the 15th.
     */
    public function testTodayIsTheSitesDay(): void
    {
        $validator = new FieldValidator(new SiteTime(new DateTimeZone('Asia/Jerusalem')), new FrozenClock('2026-10-14 22:30:00'));

        $refused = $this->failures($validator, ['date' => '2026-10-14']);
        self::assertSame('validation.form.date_min', $refused->errors['date'] ?? null);
        self::assertSame(['min' => 'Oct 15, 2026'], $refused->params['date'] ?? null, 'the bound formatted for the locale');
        self::assertArrayNotHasKey('date', $this->failures($validator, ['date' => '2026-10-15'])->errors);
        self::assertSame('validation.form.date_max', $this->failures($validator, ['date' => '2026-12-15'])->errors['date'] ?? null);
        self::assertArrayNotHasKey('date', $this->failures($validator, ['date' => '2026-12-14'])->errors, 'today + 60 days');
    }

    public function testAbsoluteBoundsOfDatesAndTimes(): void
    {
        $refused = $this->failures($this->validator(), ['fixed' => '2026-12-01', 'time' => '08:59']);
        self::assertSame('validation.form.date_max', $refused->errors['fixed'] ?? null);
        self::assertSame(['max' => 'Nov 30, 2026'], $refused->params['fixed'] ?? null);
        self::assertSame('validation.form.time_min', $refused->errors['time'] ?? null);
        self::assertSame(['min' => '09:00'], $refused->params['time'] ?? null);
        self::assertSame('validation.form.time_max', $this->failures($this->validator(), ['time' => '18:01'])->errors['time'] ?? null);
        self::assertArrayNotHasKey('time', $this->failures($this->validator(), ['time' => '18:00'])->errors);
    }

    public function testTheContactFormKeepsItsRules(): void
    {
        $contact = $this->forms->find('contact') ?? self::fail('contact expected');
        $failures = static function (array $body) use ($contact): ValidationFailed {
            $errors = new ValidationErrors();
            new FieldValidator(new SiteTime(new DateTimeZone('UTC')), new FrozenClock('2026-10-15 10:00:00'))->validate($contact, FormInput::fromBody($contact, $body), 'en', $errors);
            try {
                $errors->throwIfAny();
            } catch (ValidationFailed $failed) {
                return $failed;
            }

            return new ValidationFailed([]);
        };

        self::assertSame(
            ['name' => 'validation.field.required', 'email' => 'validation.email.invalid', 'phone' => 'validation.message.phone', 'message' => 'validation.message.too_short'],
            $failures(['name' => '  ', 'email' => 'anna@', 'phone' => 'call me', 'message' => 'Hi'])->errors,
        );
        self::assertSame(['min' => 10], $failures(['message' => 'Hi'])->params['message'] ?? null);
        self::assertSame(['email' => 'validation.field.required', 'message' => 'validation.field.required'], $failures(['name' => 'Anna'])->errors);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    private function validate(array $body): array
    {
        $errors = new ValidationErrors();
        $values = $this->validator()->validate($this->booking(), FormInput::fromBody($this->booking(), $body), 'en', $errors);
        $errors->throwIfAny();

        return $values;
    }

    /**
     * @param array<string, string> $expected
     * @param array<string, mixed> $body
     */
    private function assertRefused(array $expected, array $body): void
    {
        self::assertEqualsCanonicalizing($expected, $this->failures($this->validator(), $body)->errors);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function failures(FieldValidator $validator, array $body): ValidationFailed
    {
        $errors = new ValidationErrors();
        $validator->validate($this->booking(), FormInput::fromBody($this->booking(), $body), 'en', $errors);
        try {
            $errors->throwIfAny();
        } catch (ValidationFailed $failed) {
            return $failed;
        }

        return new ValidationFailed([]);
    }

    private function validator(): FieldValidator
    {
        return new FieldValidator(new SiteTime(new DateTimeZone('UTC')), new FrozenClock('2026-10-15 10:00:00'));
    }

    private function booking(): FormDefinition
    {
        return $this->forms->find('booking') ?? self::fail('booking expected');
    }
}
