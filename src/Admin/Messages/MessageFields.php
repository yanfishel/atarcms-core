<?php

declare(strict_types=1);

namespace AtarCms\Admin\Messages;

use AtarCms\I18n\Translator;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormLabels;
use AtarCms\Messages\Message;

/**
 * A message's form and fields as the inbox shows them in the interface language: the form's name (the theme's
 * forms.{key}.title; the contact form is "Contact form"), the rows of its fields in the form's order (the reserved
 * ones with the admin's labels, the others with the theme's, options by their labels, a checked checkbox as "Yes",
 * dates in the language's order without any time-zone conversion), fields no longer configured last under their
 * names, and the list's preview. The message text itself is shown apart.
 */
final readonly class MessageFields
{
    /** The admin's labels of the reserved fields. */
    private const array CORE_LABELS = ['name' => 'admin.messages.name', 'email' => 'admin.messages.email', 'phone' => 'admin.messages.phone'];

    public function __construct(
        private FormDefinitions $forms,
        private FormLabels $labels,
        private Translator $translator,
    ) {}

    public function title(string $locale, string $form): string
    {
        return $form === FormDefinitions::CONTACT
            ? $this->translator->translate($locale, 'admin.messages.contact')
            : $this->labels->title($locale, $form);
    }

    /**
     * @return list<array{name: string, label: string, value: string}> without the message text
     */
    public function rows(Message $message, string $locale): array
    {
        $form = $this->forms->find($message->form);
        $values = ['name' => $message->name, 'email' => $message->email, 'phone' => $message->phone, ...$message->fields];
        $names = [...array_map(static fn($field): string => $field->name, $form->fields ?? []), ...array_keys($values)];
        $rows = [];
        foreach (array_unique($names) as $name) {
            $value = $values[$name] ?? null;
            if ($value === null || $name === 'message') {
                continue;
            }
            $label = isset(self::CORE_LABELS[$name])
                ? $this->labels->field($locale, $message->form, $name, [self::CORE_LABELS[$name]])
                : $this->labels->field($locale, $message->form, $name);
            $rows[] = ['name' => $name, 'label' => $label, 'value' => $this->labels->value($locale, $message->form, $form?->field($name), $value, 'admin.messages.yes')];
        }

        return $rows;
    }

    /**
     * The list's preview: the message's first words, else its other fields' values joined with " · ".
     */
    public function preview(Message $message, string $locale): string
    {
        if ($message->body !== null) {
            return $message->preview();
        }
        $values = array_map(
            static fn(array $row): string => $row['value'],
            array_filter($this->rows($message, $locale), static fn(array $row): bool => !in_array($row['name'], ['name', 'email', 'phone'], true)),
        );

        return Message::cut(implode(' · ', $values));
    }
}
