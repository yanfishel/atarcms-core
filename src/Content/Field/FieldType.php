<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * A field type: turns submitted input into the stored JSON value and stored values into what themes read.
 */
interface FieldType
{
    /**
     * Short type name, e.g. "text"; admin widgets are chosen by it.
     */
    public function name(): string;

    /**
     * Converts submitted input into the value stored in entries.data; null means empty (the key is not stored).
     * Normalizing an already stored value must return it unchanged.
     *
     * @throws FieldError
     */
    public function normalize(mixed $input, Field $field): mixed;

    /**
     * Converts the stored value (null when absent) into the value themes get from Entry::field().
     */
    public function read(mixed $stored, Field $field): mixed;

    /**
     * SQL type of the generated column behind ->indexed(), or null when the type cannot be indexed.
     */
    public function columnType(Field $field): ?string;
}
