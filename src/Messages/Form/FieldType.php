<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

/**
 * The types of a site form's fields (config/forms.php "type"). Each is drawn as the HTML input of the same name
 * (textarea, select, a radio group, a checkbox) and validated by FieldValidator.
 */
enum FieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Tel = 'tel';
    case Textarea = 'textarea';
    case Date = 'date';
    case Time = 'time';
    case Select = 'select';
    case Radio = 'radio';
    case Checkbox = 'checkbox';

    /**
     * Whether the field chooses one of its "options".
     */
    public function hasOptions(): bool
    {
        return $this === self::Select || $this === self::Radio;
    }

    /**
     * Whether the field takes "min" and "max" bounds.
     */
    public function hasBounds(): bool
    {
        return $this === self::Date || $this === self::Time;
    }
}
