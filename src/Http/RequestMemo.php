<?php

declare(strict_types=1);

namespace AtarCms\Http;

use Closure;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A per-request memo, attached to every request by Application::handle() (attribute RequestMemo::class). View
 * helpers use it so that calling them twice on one page does not repeat their queries; services stay stateless.
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    public static function of(ServerRequestInterface $request): ?self
    {
        $memo = $request->getAttribute(self::class);

        return $memo instanceof self ? $memo : null;
    }

    /**
     * The value of $compute for $key on this request: memoised when the request carries a memo, computed every time
     * otherwise (templates rendered without a request pipeline, unit tests).
     *
     * @template T
     *
     * @param Closure(): T $compute
     *
     * @return T
     */
    public static function through(ServerRequestInterface $request, string $key, Closure $compute): mixed
    {
        $memo = self::of($request);

        return $memo === null ? $compute() : $memo->remember($key, $compute);
    }

    /**
     * @template T
     *
     * @param Closure(): T $compute
     *
     * @return T
     */
    public function remember(string $key, Closure $compute): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $compute();
        }

        /** @var T */
        return $this->values[$key];
    }
}
