<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

use RuntimeException;

/**
 * A rejected field value: a translation key plus the ICU parameters of its message.
 */
final class FieldError extends RuntimeException
{
    /**
     * @param array<string, int|float|string> $params
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {
        parent::__construct($key);
    }
}
