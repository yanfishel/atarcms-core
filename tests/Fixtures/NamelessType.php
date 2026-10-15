<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures;

use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\FieldType;

/**
 * A custom type with no widget template: the admin falls back to fields/default.
 */
abstract class NamelessType implements FieldType
{
    public function name(): string
    {
        return 'Not A Template Name';
    }

    public function normalize(mixed $input, Field $field): mixed
    {
        return $input;
    }

    public function read(mixed $stored, Field $field): mixed
    {
        return $stored;
    }

    public function columnType(Field $field): ?string
    {
        return null;
    }
}
