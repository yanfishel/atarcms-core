<?php

declare(strict_types=1);

namespace AtarCms\Http\Security;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CspNonce
{
    private function __construct(public string $value) {}

    public static function generate(): self
    {
        return new self(base64_encode(random_bytes(16)));
    }

    public static function of(ServerRequestInterface $request): self
    {
        $nonce = $request->getAttribute(self::class);
        if (!$nonce instanceof self) {
            throw new LogicException('No CSP nonce on the request; is SecurityHeadersMiddleware in the pipeline?');
        }

        return $nonce;
    }
}
