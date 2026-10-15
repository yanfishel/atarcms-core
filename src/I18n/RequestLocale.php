<?php

declare(strict_types=1);

namespace AtarCms\I18n;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The locale of the current request; "prefixed" means it came from a /{locale} URL prefix.
 */
final readonly class RequestLocale
{
    public function __construct(
        public string $code,
        public bool $prefixed,
    ) {}

    public static function of(ServerRequestInterface $request): self
    {
        $locale = $request->getAttribute(self::class);
        if (!$locale instanceof self) {
            throw new LogicException('No locale on the request; is LocaleMiddleware in the pipeline?');
        }

        return $locale;
    }
}
