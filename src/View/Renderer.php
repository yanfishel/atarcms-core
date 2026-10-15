<?php

declare(strict_types=1);

namespace AtarCms\View;

use BadMethodCallException;
use Closure;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

final class Renderer
{
    /** @var array<string, Closure> */
    private array $helpers = [];

    /** @var array<string, Closure> */
    private array $requestHelpers = [];

    /** @var array<string, Closure> */
    private array $optionalRequestHelpers = [];

    public function __construct(private readonly ThemeResolver $resolver) {}

    public function helper(string $name, Closure $helper): void
    {
        $this->assertHelperName($name);
        $this->helpers[$name] = $helper;
    }

    /**
     * Registers a helper that receives the request being rendered as its first argument.
     */
    public function requestHelper(string $name, Closure $helper): void
    {
        $this->assertHelperName($name);
        $this->requestHelpers[$name] = $helper;
    }

    /**
     * Registers a helper that receives the request being rendered, or null when rendering without one, as its first
     * argument: for helpers that use the request only for optional extras such as the per-request memo.
     */
    public function optionalRequestHelper(string $name, Closure $helper): void
    {
        $this->assertHelperName($name);
        $this->optionalRequestHelpers[$name] = $helper;
    }

    /**
     * @param array<mixed> $arguments
     */
    public function callHelper(string $name, array $arguments, ?ServerRequestInterface $request = null): mixed
    {
        if (isset($this->helpers[$name])) {
            return ($this->helpers[$name])(...$arguments);
        }
        if (isset($this->optionalRequestHelpers[$name])) {
            return ($this->optionalRequestHelpers[$name])($request, ...$arguments);
        }
        $helper = $this->requestHelpers[$name] ?? throw new BadMethodCallException(sprintf('Unknown template helper "%s"', $name));
        if ($request === null) {
            throw new LogicException(sprintf(
                'Template helper "%s" needs the current request; pass it to Renderer::render()',
                $name,
            ));
        }

        return $helper($request, ...$arguments);
    }

    /**
     * @param string|list<string> $template candidate names, highest priority first
     * @param array<string, mixed> $data
     */
    public function render(string|array $template, array $data = [], ?ServerRequestInterface $request = null): string
    {
        $file = $this->resolver->resolve(...(array) $template);
        $context = new Template($this, $request);
        $content = $context->capture($file, $data);

        $layout = $context->layoutName();
        if ($layout === null) {
            return $content;
        }

        return $this->render($layout, [...$data, ...$context->layoutData(), 'content' => $content], $request);
    }

    private function assertHelperName(string $name): void
    {
        if (method_exists(Template::class, $name)) {
            throw new LogicException(sprintf('Helper "%s" conflicts with a built-in template method', $name));
        }
        if (isset($this->helpers[$name]) || isset($this->requestHelpers[$name]) || isset($this->optionalRequestHelpers[$name])) {
            throw new LogicException(sprintf('Helper "%s" is already registered', $name));
        }
    }
}
