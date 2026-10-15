<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

use AtarCms\Content\ValidationErrors;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\SiteTime;

/**
 * Checks and cleans the fields of a submitted site form by type. Single-line text loses control characters and runs
 * of whitespace; a textarea keeps its line breaks; a checked checkbox is "1"; a select or radio value must be one of
 * its options; a date is a real "Y-m-d" and a time "H:i", both wall-clock values within the field's bounds ("today"
 * being the site's day: SiteTime with the Clock). Errors are translation keys with ICU parameters.
 */
final readonly class FieldValidator
{
    public const int MAX_LINE = 200;

    public const int MAX_NAME = 100;

    public const int MAX_EMAIL = 200;

    public const int MAX_PHONE = 50;

    public const int MAX_TEXT = 5000;

    /** The contact form's message is at least this long. */
    public const int MIN_CONTACT_MESSAGE = 10;

    public function __construct(
        private SiteTime $time,
        private Clock $clock,
    ) {}

    /**
     * @param string $locale the visitor's language: date bounds in error parameters are written for it
     *
     * @return array<string, string> the cleaned values of the filled-in fields, in the form's order
     */
    public function validate(FormDefinition $form, FormInput $input, string $locale, ValidationErrors $errors): array
    {
        $today = $this->time->today($this->clock->now());
        $values = [];
        foreach ($form->fields as $field) {
            $value = $this->field($form, $field, $input->value($field->name), $today, $locale, $errors);
            if ($value !== '') {
                $values[$field->name] = $value;
            }
        }

        return $values;
    }

    private function field(FormDefinition $form, FormField $field, string $raw, string $today, string $locale, ValidationErrors $errors): string
    {
        $name = $field->name;
        $value = $field->type === FieldType::Textarea ? self::text($raw) : self::line($raw);
        if ($field->type === FieldType::Checkbox) {
            if ($value === '' && $field->required) {
                $errors->add($name, 'validation.form.checkbox');
            }

            return $value === '' ? '' : '1';
        }
        if ($value === '') {
            if ($field->required) {
                $errors->add($name, 'validation.field.required');
            }

            return '';
        }

        switch ($field->type) {
            case FieldType::Text:
                self::maxLength($name, $value, $name === 'name' ? self::MAX_NAME : self::MAX_LINE, $errors);
                break;
            case FieldType::Email:
                self::email($name, $value, $errors);
                break;
            case FieldType::Tel:
                self::tel($name, $value, $errors);
                break;
            case FieldType::Textarea:
                self::textarea($form, $name, $value, $errors);
                break;
            case FieldType::Select:
            case FieldType::Radio:
                if (!in_array($value, $field->options, true)) {
                    $errors->add($name, 'validation.form.option');
                }
                break;
            case FieldType::Date:
                $this->date($field, $value, $today, $locale, $errors);
                break;
            case FieldType::Time:
                self::time($field, $value, $today, $errors);
                break;
        }

        return $value;
    }

    private static function email(string $name, string $value, ValidationErrors $errors): void
    {
        if (mb_strlen($value) > self::MAX_EMAIL) {
            $errors->add($name, 'validation.field.too_long', ['max' => self::MAX_EMAIL]);
        } elseif (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $errors->add($name, 'validation.email.invalid');
        }
    }

    /**
     * The reserved "phone" keeps the contact form's phone pattern; another tel field is a line of at most MAX_LINE.
     */
    private static function tel(string $name, string $value, ValidationErrors $errors): void
    {
        if ($name !== 'phone') {
            self::maxLength($name, $value, self::MAX_LINE, $errors);
        } elseif (preg_match('/^\+?[0-9][0-9 ().-]{4,' . (self::MAX_PHONE - 2) . '}$/D', $value) !== 1) {
            $errors->add($name, 'validation.message.phone');
        }
    }

    private static function textarea(FormDefinition $form, string $name, string $value, ValidationErrors $errors): void
    {
        if ($form->isContact() && $name === 'message' && mb_strlen($value) < self::MIN_CONTACT_MESSAGE) {
            $errors->add($name, 'validation.message.too_short', ['min' => self::MIN_CONTACT_MESSAGE]);
        } else {
            self::maxLength($name, $value, self::MAX_TEXT, $errors);
        }
    }

    private function date(FormField $field, string $value, string $today, string $locale, ValidationErrors $errors): void
    {
        if (!FormField::isDate($value)) {
            $errors->add($field->name, 'validation.form.date');

            return;
        }
        $min = $field->minValue($today);
        $max = $field->maxValue($today);
        if ($min !== null && $value < $min) {
            $errors->add($field->name, 'validation.form.date_min', ['min' => $this->time->readableWallDate($min, $locale)]);
        } elseif ($max !== null && $value > $max) {
            $errors->add($field->name, 'validation.form.date_max', ['max' => $this->time->readableWallDate($max, $locale)]);
        }
    }

    private static function time(FormField $field, string $value, string $today, ValidationErrors $errors): void
    {
        if (!FormField::isTime($value)) {
            $errors->add($field->name, 'validation.form.time');

            return;
        }
        $min = $field->minValue($today);
        $max = $field->maxValue($today);
        if ($min !== null && $value < $min) {
            $errors->add($field->name, 'validation.form.time_min', ['min' => $min]);
        } elseif ($max !== null && $value > $max) {
            $errors->add($field->name, 'validation.form.time_max', ['max' => $max]);
        }
    }

    private static function maxLength(string $name, string $value, int $max, ValidationErrors $errors): void
    {
        if (mb_strlen($value) > $max) {
            $errors->add($name, 'validation.field.too_long', ['max' => $max]);
        }
    }

    /**
     * Single-line text: control characters and runs of whitespace become one space, trimmed.
     */
    private static function line(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', mb_scrub($value, 'UTF-8')));
    }

    /**
     * Multi-line text: line breaks kept as "\n", other control characters dropped, trimmed.
     */
    private static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", mb_scrub($value, 'UTF-8'));

        return trim((string) preg_replace('/[^\P{Cc}\n\t]/u', '', $value));
    }
}
