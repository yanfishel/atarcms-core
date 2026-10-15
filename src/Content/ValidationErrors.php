<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Http\Exception\ValidationFailed;

/**
 * Collects validation errors (the first error per field wins) and throws them as one ValidationFailed.
 */
final class ValidationErrors
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, array<string, int|float|string>> */
    private array $params = [];

    /**
     * @param array<string, int|float|string> $params
     */
    public function add(string $field, string $key, array $params = []): void
    {
        if (isset($this->errors[$field])) {
            return;
        }
        $this->errors[$field] = $key;
        if ($params !== []) {
            $this->params[$field] = $params;
        }
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * @throws ValidationFailed
     */
    public function throwIfAny(): void
    {
        if ($this->errors !== []) {
            throw new ValidationFailed($this->errors, $this->params);
        }
    }
}
