<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;

/**
 * The site's forms: the built-in contact form and the forms of config/forms.php ("forms"), checked at boot so a
 * mistake stops the site with a message naming the form and the field. Each form is
 * ['recipient' => ?e-mail, 'fields' => [name => ['type' => …, 'required' => bool, 'options' => […], 'min' => …,
 * 'max' => …]]]. The fields name, email, phone and message are reserved (stored in the message's own columns, with a
 * fixed type); "website" (the honeypot) and "form" (the form-level error) are no field names. A form needs a required
 * e-mail or phone, a way to answer. Without a "contact" entry the contact form has today's four fields.
 */
final readonly class FormDefinitions
{
    public const string CONTACT = 'contact';

    public const int MAX_KEY = 40;

    /** The reserved field names and their fixed types. */
    public const array RESERVED = [
        'name' => FieldType::Text,
        'email' => FieldType::Email,
        'phone' => FieldType::Tel,
        'message' => FieldType::Textarea,
    ];

    /** Names no field may take: the honeypot and the key of form-level errors. */
    public const array FORBIDDEN = ['website', 'form'];

    private const string NAME = '/^[a-z][a-z0-9_]*$/D';

    private const string OPTION = '/^[a-z0-9][a-z0-9_-]*$/D';

    private const array FORM_KEYS = ['recipient', 'fields'];

    private const array FIELD_KEYS = ['type', 'required', 'options', 'min', 'max'];

    /**
     * @param array<string, FormDefinition> $forms by key, the contact form first
     * @param list<string> $configured the keys config/forms.php defines
     */
    private function __construct(private array $forms, private array $configured = []) {}

    /**
     * @throws ConfigException for a form or field config/forms.php gets wrong
     */
    public static function fromConfig(Config $config): self
    {
        $configured = $config->get('forms', []);
        if (!is_array($configured)) {
            throw new ConfigException(sprintf('config/forms.php must return an array of forms by key, got %s', get_debug_type($configured)));
        }
        $forms = [self::CONTACT => self::contact()];
        $keys = [];
        foreach ($configured as $key => $form) {
            $key = (string) $key;
            $forms[$key] = self::form($key, $form);
            $keys[] = $key;
        }

        return new self($forms, $keys);
    }

    /**
     * @return list<FormDefinition> the forms config/forms.php defines (the contact form only when it is replaced there)
     */
    public function configured(): array
    {
        return array_map(fn(string $key): FormDefinition => $this->forms[$key], $this->configured);
    }

    public function find(string $key): ?FormDefinition
    {
        return $this->forms[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->forms[$key]);
    }

    /**
     * @return list<FormDefinition> the contact form first, then the configured forms in config order
     */
    public function all(): array
    {
        return array_values($this->forms);
    }

    private static function contact(): FormDefinition
    {
        return new FormDefinition(self::CONTACT, null, [
            new FormField('name', FieldType::Text, true),
            new FormField('email', FieldType::Email, true),
            new FormField('phone', FieldType::Tel),
            new FormField('message', FieldType::Textarea, true),
        ]);
    }

    private static function form(string $key, mixed $form): FormDefinition
    {
        $where = sprintf('form "%s"', $key);
        if (preg_match(self::NAME, $key) !== 1 || strlen($key) > self::MAX_KEY) {
            throw self::error($where, sprintf('a form key is lower-case letters, digits and "_", starting with a letter, at most %d characters', self::MAX_KEY));
        }
        if (!is_array($form)) {
            throw self::error($where, sprintf('a form is an array with "fields", got %s', get_debug_type($form)));
        }
        self::knownKeys($where, $form, self::FORM_KEYS);
        $recipient = $form['recipient'] ?? null;
        if ($recipient !== null && (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false)) {
            throw self::error($where, '"recipient" must be null (the site settings\' e-mail) or an e-mail address');
        }
        $fields = $form['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            throw self::error($where, '"fields" must be a non-empty array of fields by name');
        }
        $list = [];
        foreach ($fields as $name => $field) {
            $list[] = self::field($key, (string) $name, $field);
        }
        $answerable = array_filter($list, static fn(FormField $field): bool => $field->required && ($field->name === 'email' || $field->name === 'phone'));
        if ($answerable === []) {
            throw self::error($where, 'a form needs a required "email" or a required "phone" field, a way to answer');
        }

        return new FormDefinition($key, $recipient, $list);
    }

    private static function field(string $form, string $name, mixed $field): FormField
    {
        $where = sprintf('form "%s", field "%s"', $form, $name);
        if (preg_match(self::NAME, $name) !== 1) {
            throw self::error($where, 'a field name is lower-case letters, digits and "_", starting with a letter');
        }
        if (in_array($name, self::FORBIDDEN, true)) {
            throw self::error($where, sprintf('"%s" is no field name (reserved: %s)', $name, implode(', ', self::FORBIDDEN)));
        }
        if (!is_array($field)) {
            throw self::error($where, sprintf('a field is an array with a "type", got %s', get_debug_type($field)));
        }
        self::knownKeys($where, $field, self::FIELD_KEYS);
        $type = is_string($field['type'] ?? null) ? FieldType::tryFrom($field['type']) : null;
        if ($type === null) {
            throw self::error($where, sprintf('"type" must be one of %s', implode(', ', array_map(static fn(FieldType $type): string => $type->value, FieldType::cases()))));
        }
        $reserved = self::RESERVED[$name] ?? null;
        if ($reserved !== null && $reserved !== $type) {
            throw self::error($where, sprintf('"%s" is reserved for a field of type %s', $name, $reserved->value));
        }
        $required = $field['required'] ?? false;
        if (!is_bool($required)) {
            throw self::error($where, '"required" must be true or false');
        }

        return new FormField($name, $type, $required, self::options($where, $type, $field), ...self::bounds($where, $type, $field));
    }

    /**
     * @param array<mixed> $field
     *
     * @return list<string>
     */
    private static function options(string $where, FieldType $type, array $field): array
    {
        if (!$type->hasOptions()) {
            if (array_key_exists('options', $field)) {
                throw self::error($where, '"options" belong to select and radio fields only');
            }

            return [];
        }
        $options = $field['options'] ?? null;
        if (!is_array($options) || $options === [] || !array_is_list($options)) {
            throw self::error($where, 'a select or radio field needs a non-empty list of option keys in "options"');
        }
        foreach ($options as $option) {
            if (!is_string($option) || preg_match(self::OPTION, $option) !== 1) {
                throw self::error($where, sprintf('option keys are lower-case letters, digits, "_" and "-", got %s', is_string($option) ? '"' . $option . '"' : get_debug_type($option)));
            }
        }
        if (count(array_unique($options)) !== count($options)) {
            throw self::error($where, 'option keys must be distinct');
        }

        return $options;
    }

    /**
     * @param array<mixed> $field
     *
     * @return array{min: ?string, max: ?string}
     */
    private static function bounds(string $where, FieldType $type, array $field): array
    {
        $bounds = ['min' => null, 'max' => null];
        foreach (['min', 'max'] as $side) {
            if (!array_key_exists($side, $field)) {
                continue;
            }
            $bound = $field[$side];
            if (!$type->hasBounds()) {
                throw self::error($where, sprintf('"%s" belongs to date and time fields only', $side));
            }
            if (!is_string($bound) || !FormField::isBound($type, $bound)) {
                throw self::error($where, sprintf(
                    '"%s" must be %s',
                    $side,
                    $type === FieldType::Date ? '"today", "+N days" or a date "Y-m-d"' : 'a time "H:i" (00:00–23:59)',
                ));
            }
            $bounds[$side] = $bound;
        }
        [$min, $max] = [$bounds['min'], $bounds['max']];
        if ($min !== null && $max !== null && self::after($type, $min, $max)) {
            throw self::error($where, '"min" must not be after "max"');
        }

        return $bounds;
    }

    /**
     * Whether bound $min lies after $max, when both are absolute or both are relative (a relative and an absolute
     * date bound depend on the day).
     */
    private static function after(FieldType $type, string $min, string $max): bool
    {
        if ($type === FieldType::Date) {
            [$minDays, $maxDays] = [FormField::relativeDays($min), FormField::relativeDays($max)];
            if ($minDays !== null || $maxDays !== null) {
                return $minDays !== null && $maxDays !== null && $minDays > $maxDays;
            }
        }

        return strcmp($min, $max) > 0;
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $known
     */
    private static function knownKeys(string $where, array $values, array $known): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($values)), $known);
        if ($unknown !== []) {
            throw self::error($where, sprintf('unknown key(s) %s; known: %s', implode(', ', $unknown), implode(', ', $known)));
        }
    }

    private static function error(string $where, string $problem): ConfigException
    {
        return new ConfigException(sprintf('config/forms.php: %s: %s', $where, $problem));
    }
}
