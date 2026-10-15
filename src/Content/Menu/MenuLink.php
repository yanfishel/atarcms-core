<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

use Stringable;

/**
 * One visible link of a public menu: $this->menu('main') returns the top level, each link its children. The label is
 * user input: escape it ($this->e($link) or $this->e($link->label)), and the URL ($this->e($link->url)).
 */
final readonly class MenuLink implements Stringable
{
    /**
     * @param bool $current the link points at the requested page
     * @param bool $ancestorOfCurrent one of its descendants points at the requested page
     * @param list<MenuLink> $children
     */
    public function __construct(
        public int $id,
        public string $label,
        public string $url,
        public bool $newTab,
        public bool $current,
        public bool $ancestorOfCurrent,
        public array $children,
    ) {}

    public function __toString(): string
    {
        return $this->label;
    }
}
