<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures;

use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\FieldType;

/**
 * A custom field type whose admin widget comes from FixtureModule::adminTemplatePaths().
 */
final class ColorType implements FieldType
{
    public function name(): string
    {
        return 'color';
    }

    public function normalize(mixed $input, Field $field): ?string
    {
        return is_string($input) && preg_match('/^#[0-9a-f]{6}$/', $input) === 1 ? $input : null;
    }

    public function read(mixed $stored, Field $field): ?string
    {
        return is_string($stored) ? $stored : null;
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }
}
