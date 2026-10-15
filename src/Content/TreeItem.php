<?php

declare(strict_types=1);

namespace AtarCms\Content;

/**
 * Something the admin tree editor orders: entries of hierarchical collections, terms of hierarchical taxonomies
 * and menu items. Readonly public properties satisfy these get-only properties.
 */
interface TreeItem
{
    public int $id { get; }

    public ?int $parentId { get; }

    public int $position { get; }
}
