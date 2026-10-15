<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Content\Field\Field;

/**
 * The icon in front of a field's label in the entry form, by field type (FieldType::name()).
 */
final class FieldIcons
{
    public const array ICONS = [
        'text' => 'type',
        'textarea' => 'align-left',
        'richText' => 'align-left',
        'image' => 'image',
        'gallery' => 'images',
        'slides' => 'images',
        'file' => 'paperclip',
        'date' => 'calendar',
        'datetime' => 'calendar',
        'youtube' => 'video',
        'tags' => 'tags',
        'relation' => 'link',
        'boolean' => 'toggle-left',
        'number' => 'hash',
        'select' => 'list',
    ];

    /** The icon of types without one of their own (those of site modules). */
    public const string DEFAULT = 'circle';

    public static function of(Field $field): string
    {
        return self::ICONS[$field->type->name()] ?? self::DEFAULT;
    }
}
