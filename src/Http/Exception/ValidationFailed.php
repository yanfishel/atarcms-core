<?php

declare(strict_types=1);

namespace AtarCms\Http\Exception;

final class ValidationFailed extends HttpException
{
    /**
     * @param array<string, string> $errors field name => translation key
     * @param array<string, array<string, int|float|string>> $params field name => ICU parameters of its message
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $params = [],
    ) {
        $parts = [];
        foreach ($errors as $field => $message) {
            $parts[] = $field . ': ' . $message;
        }
        parent::__construct('Validation failed: ' . implode('; ', $parts));
    }

    public function statusCode(): int
    {
        return 422;
    }
}
