<?php

declare(strict_types=1);

namespace AtarCms\View;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Stringable;
use Throwable;

/**
 * Scope object for theme templates: inside a template `$this` is this class.
 */
final class Template
{
    private ?string $layout = null;

    /** @var array<string, mixed> */
    private array $layoutData = [];

    public function __construct(
        private readonly Renderer $renderer,
        private readonly ?ServerRequestInterface $request = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function capture(string $file, array $data): string
    {
        foreach (array_keys($data) as $key) {
            if ($key === 'this' || str_starts_with($key, '__') || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid template variable name "%s"', $key));
            }
        }

        $level = ob_get_level();
        ob_start();
        try {
            (function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                include $__file;
            })->call($this, $file, $data);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }
    }

    public function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_scalar($value) && !$value instanceof Stringable) {
            throw new InvalidArgumentException(sprintf('Cannot escape value of type %s', get_debug_type($value)));
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Renders this template inside the layout $name. The layout receives the template's data, then $data,
     * and the rendered template as $content, which replaces any data key named "content".
     *
     * @param array<string, mixed> $data
     */
    public function layout(string $name, array $data = []): void
    {
        $this->layout = $name;
        $this->layoutData = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function partial(string $name, array $data = []): string
    {
        return $this->renderer->render($name, $data, $this->request);
    }

    public function layoutName(): ?string
    {
        return $this->layout;
    }

    /**
     * @return array<string, mixed>
     */
    public function layoutData(): array
    {
        return $this->layoutData;
    }

    /**
     * @param array<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->renderer->callHelper($name, $arguments, $this->request);
    }
}
