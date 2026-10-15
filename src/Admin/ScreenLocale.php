<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The content language of the admin screen in the linked mode, put on the request by AdminLocale (the request
 * attribute ScreenLocale::class) before the controller runs: ContentLocale::current() reads it, so the content of the
 * screen and its interface speak one language. The separate mode puts nothing.
 */
final readonly class ScreenLocale
{
    public function __construct(public string $code) {}

    public static function of(ServerRequestInterface $request): ?string
    {
        $screen = $request->getAttribute(self::class);

        return $screen instanceof self ? $screen->code : null;
    }
}
