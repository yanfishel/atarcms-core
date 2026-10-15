<?php

declare(strict_types=1);

namespace AtarCms\Http\Exception;

final class MethodNotAllowed extends HttpException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(public readonly array $allowed)
    {
        parent::__construct('Method not allowed; allowed: ' . implode(', ', $allowed));
    }

    public function statusCode(): int
    {
        return 405;
    }

    public function headers(): array
    {
        return ['Allow' => implode(', ', $this->allowed)];
    }
}
