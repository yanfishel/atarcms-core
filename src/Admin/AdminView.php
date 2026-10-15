<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\View\Renderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin's own renderer (resources/admin/templates), separate from the site theme.
 */
final class AdminView
{
    public function __construct(private readonly Renderer $renderer) {}

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, ServerRequestInterface $request, array $data = []): string
    {
        return $this->renderer->render($template, $data, $request);
    }

    public function renderer(): Renderer
    {
        return $this->renderer;
    }
}
