<?php

declare(strict_types=1);

namespace AtarCms\Http\Routing;

use LogicException;
use Psr\Http\Server\MiddlewareInterface;

final class Route
{
    /** @var list<class-string<MiddlewareInterface>> */
    private array $middleware = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    private bool $stateless = false;

    /** The name of the public form this POST route receives (publicForm()), or null. */
    public private(set) ?string $publicForm = null;

    /**
     * @param list<string> $methods
     * @param array{0: class-string, 1: string} $handler
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly array $handler,
        public readonly ?string $name = null,
    ) {}

    /**
     * @param class-string<MiddlewareInterface> ...$middleware
     */
    public function middleware(string ...$middleware): self
    {
        if ($this->stateless) {
            $this->assertStatelessMiddleware($middleware);
        }
        $this->middleware = [...$this->middleware, ...array_values($middleware)];

        return $this;
    }

    /**
     * Runs this route without the session: SessionMiddleware neither reads nor writes it (no Set-Cookie), so there
     * is no current user. Only for GET routes (HEAD follows GET) that never need the user; combining it with
     * middleware that does (RequiresSession: RequireAuthentication, RequirePermission) is an error in either order.
     */
    public function stateless(): self
    {
        if ($this->methods !== ['GET']) {
            throw new LogicException(sprintf('Only GET routes can be stateless; %s is %s', $this->path, implode(', ', $this->methods)));
        }
        $this->assertStatelessMiddleware($this->middleware);
        $this->stateless = true;

        return $this;
    }

    /**
     * Receives a public form (a visitor's contact form) without the session: like stateless(), but for one POST route.
     * There is no session token, so CsrfMiddleware refuses posts that browsers mark as cross-site (Sec-Fetch-Site) or
     * that come from another origin than app.url; the controller checks the form's signed token (PublicFormToken).
     */
    public function publicForm(string $form): self
    {
        if ($this->methods !== ['POST']) {
            throw new LogicException(sprintf('Only POST routes can be public forms; %s is %s', $this->path, implode(', ', $this->methods)));
        }
        $this->assertStatelessMiddleware($this->middleware);
        $this->stateless = true;
        $this->publicForm = $form;

        return $this;
    }

    public function isStateless(): bool
    {
        return $this->stateless;
    }

    /**
     * @return list<class-string<MiddlewareInterface>>
     */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    /**
     * Sets a request attribute whenever this route matches. Use class-string keys and plain data values (content
     * routes carry collection and taxonomy names, not objects).
     */
    public function attribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<class-string<MiddlewareInterface>> $middleware
     */
    private function assertStatelessMiddleware(array $middleware): void
    {
        foreach ($middleware as $class) {
            if (is_subclass_of($class, RequiresSession::class)) {
                throw new LogicException(sprintf('The stateless route %s cannot use %s, which needs the session', $this->path, $class));
            }
        }
    }
}
