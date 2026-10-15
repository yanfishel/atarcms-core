<?php

declare(strict_types=1);

namespace AtarCms\Http\Session;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Per-request session state; SessionMiddleware loads and persists it.
 */
final class Session
{
    private bool $dirty = false;

    private bool $regenerate = false;

    private bool $invalidated = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly ?string $id = null,
        private array $data = [],
    ) {}

    public static function of(ServerRequestInterface $request): self
    {
        $session = $request->getAttribute(self::class);
        if (!$session instanceof self) {
            throw new LogicException('No session on the request; is SessionMiddleware in the pipeline?');
        }

        return $session;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function remove(string $key): void
    {
        if ($this->has($key)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Issue a new session id when the session is saved, keeping the data. Call on login.
     */
    public function regenerate(): void
    {
        $this->regenerate = true;
        $this->dirty = true;
    }

    /**
     * Drop all data and the session id. Call on logout.
     */
    public function invalidate(): void
    {
        $this->data = [];
        $this->invalidated = true;
        $this->regenerate = false;
        $this->dirty = true;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function needsNewId(): bool
    {
        return $this->regenerate;
    }

    public function isInvalidated(): bool
    {
        return $this->invalidated;
    }
}
