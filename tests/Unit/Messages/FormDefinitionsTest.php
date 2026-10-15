<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Messages;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Messages\Form\FieldType;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormDefinitionsTest extends TestCase
{
    private const array BOOKING = [
        'recipient' => 'bookings@example.com',
        'fields' => [
            'name' => ['type' => 'text', 'required' => true],
            'phone' => ['type' => 'tel', 'required' => true],
            'email' => ['type' => 'email'],
            'service' => ['type' => 'select', 'required' => true, 'options' => ['haircut', 'coloring']],
            'date' => ['type' => 'date', 'required' => true, 'min' => 'today', 'max' => '+60 days'],
            'time' => ['type' => 'time', 'min' => '09:00', 'max' => '18:00'],
            'consent' => ['type' => 'checkbox', 'required' => true],
            'message' => ['type' => 'textarea'],
        ],
    ];

    public function testWithoutFormsTheSiteHasTheBuiltInContactForm(): void
    {
        $forms = FormDefinitions::fromConfig(new Config());

        self::assertSame([FormDefinitions::CONTACT], array_map(static fn($form): string => $form->key, $forms->all()));
        $contact = $forms->find('contact') ?? self::fail('contact expected');
        self::assertTrue($contact->isContact());
        self::assertNull($contact->recipient);
        self::assertSame(
            [['name', FieldType::Text, true], ['email', FieldType::Email, true], ['phone', FieldType::Tel, false], ['message', FieldType::Textarea, true]],
            array_map(static fn(FormField $field): array => [$field->name, $field->type, $field->required], $contact->fields),
        );
        self::assertNull($forms->find('booking'));
        self::assertFalse($forms->has('booking'));
    }

    public function testConfiguredFormsKeepTheirFieldOrderAfterTheContactForm(): void
    {
        $forms = FormDefinitions::fromConfig(new Config(['forms' => ['booking' => self::BOOKING]]));

        self::assertSame(['contact', 'booking'], array_map(static fn($form): string => $form->key, $forms->all()));
        $booking = $forms->find('booking') ?? self::fail('booking expected');
        self::assertFalse($booking->isContact());
        self::assertSame('bookings@example.com', $booking->recipient);
        self::assertSame(['name', 'phone', 'email', 'service', 'date', 'time', 'consent', 'message'], array_map(static fn(FormField $field): string => $field->name, $booking->fields));
        self::assertSame(['haircut', 'coloring'], $booking->field('service')?->options);
        self::assertSame(['today', '+60 days'], [$booking->field('date')?->min, $booking->field('date')?->max]);
        self::assertSame(FieldType::Checkbox, $booking->field('consent')?->type);
        self::assertFalse($booking->field('email')?->required);
        self::assertNull($booking->field('ghost'));
    }

    public function testASiteMayOverrideTheContactForm(): void
    {
        $forms = FormDefinitions::fromConfig(new Config(['forms' => [
            'booking' => self::BOOKING,
            'contact' => ['fields' => ['email' => ['type' => 'email', 'required' => true], 'message' => ['type' => 'textarea', 'required' => true]]],
        ]]));

        self::assertSame(['contact', 'booking'], array_map(static fn($form): string => $form->key, $forms->all()));
        self::assertSame(['booking', 'contact'], array_map(static fn($form): string => $form->key, $forms->configured()), 'the forms of config/forms.php');
        self::assertSame([], FormDefinitions::fromConfig(new Config())->configured());
        self::assertSame(['email', 'message'], array_map(static fn(FormField $field): string => $field->name, ($forms->find('contact') ?? self::fail('contact expected'))->fields));
    }

    /**
     * @param array<string, mixed> $forms
     */
    #[DataProvider('invalidForms')]
    public function testAnInvalidFormStopsTheBoot(array $forms, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        FormDefinitions::fromConfig(new Config(['forms' => $forms]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidForms(): iterable
    {
        $fields = self::BOOKING['fields'];
        $with = static fn(array $extra): array => ['booking' => ['fields' => [...$fields, ...$extra]]];

        yield 'bad form key' => [['Booking' => self::BOOKING], 'form "Booking"'];
        yield 'long form key' => [[str_repeat('a', 41) => self::BOOKING], 'form "' . str_repeat('a', 41) . '"'];
        yield 'not a list of forms' => [['booking' => 'yes'], 'form "booking"'];
        yield 'bad field name' => [$with(['Shoe-size' => ['type' => 'text']]), 'form "booking", field "Shoe-size"'];
        yield 'unknown type' => [$with(['size' => ['type' => 'number']]), 'form "booking", field "size"'];
        yield 'missing type' => [$with(['size' => ['required' => true]]), 'form "booking", field "size"'];
        yield 'reserved name with another type' => [$with(['message' => ['type' => 'text']]), 'form "booking", field "message"'];
        yield 'reserved email as text' => [$with(['email' => ['type' => 'text']]), 'form "booking", field "email"'];
        yield 'the honeypot as a field' => [$with(['website' => ['type' => 'text']]), 'form "booking", field "website"'];
        yield 'the form error key as a field' => [$with(['form' => ['type' => 'text']]), 'form "booking", field "form"'];
        yield 'select without options' => [$with(['size' => ['type' => 'select']]), 'form "booking", field "size"'];
        yield 'radio with empty options' => [$with(['size' => ['type' => 'radio', 'options' => []]]), 'form "booking", field "size"'];
        yield 'duplicate options' => [$with(['size' => ['type' => 'radio', 'options' => ['s', 's']]]), 'form "booking", field "size"'];
        yield 'malformed option' => [$with(['size' => ['type' => 'select', 'options' => ['Small size']]]), 'form "booking", field "size"'];
        yield 'options on a text field' => [$with(['size' => ['type' => 'text', 'options' => ['s']]]), 'form "booking", field "size"'];
        yield 'bounds on a text field' => [$with(['size' => ['type' => 'text', 'min' => '1']]), 'form "booking", field "size"'];
        yield 'unparsable date bound' => [$with(['date' => ['type' => 'date', 'min' => 'tomorrow']]), 'form "booking", field "date"'];
        yield 'impossible date bound' => [$with(['date' => ['type' => 'date', 'max' => '2026-02-30']]), 'form "booking", field "date"'];
        yield 'unparsable time bound' => [$with(['time' => ['type' => 'time', 'min' => '9:00']]), 'form "booking", field "time"'];
        yield 'time bound out of the day' => [$with(['time' => ['type' => 'time', 'max' => '24:00']]), 'form "booking", field "time"'];
        yield 'date min after max' => [$with(['date' => ['type' => 'date', 'min' => '2026-12-01', 'max' => '2026-11-01']]), 'form "booking", field "date"'];
        yield 'relative date min after max' => [$with(['date' => ['type' => 'date', 'min' => '+10 days', 'max' => 'today']]), 'form "booking", field "date"'];
        yield 'time min after max' => [$with(['time' => ['type' => 'time', 'min' => '18:00', 'max' => '09:00']]), 'form "booking", field "time"'];
        yield 'not a boolean required' => [$with(['size' => ['type' => 'text', 'required' => 'yes']]), 'form "booking", field "size"'];
        yield 'unknown field key' => [$with(['size' => ['type' => 'text', 'label' => 'Size']]), 'form "booking", field "size"'];
        yield 'unknown form key' => [['booking' => [...self::BOOKING, 'subject' => 'Booking']], 'form "booking"'];
        yield 'invalid recipient' => [['booking' => [...self::BOOKING, 'recipient' => 'office']], 'form "booking"'];
        yield 'no fields' => [['booking' => ['fields' => []]], 'form "booking"'];
        yield 'no way to answer' => [['booking' => ['fields' => ['name' => ['type' => 'text'], 'email' => ['type' => 'email'], 'phone' => ['type' => 'tel']]]], 'form "booking"'];
    }

    public function testFormsMustBeAList(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('forms');

        FormDefinitions::fromConfig(new Config(['forms' => ['booking']]));
    }
}
