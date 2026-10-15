<?php

declare(strict_types=1);

namespace AtarCms\Content;

/**
 * One node of a submitted or stored tree: an item id and its children in order.
 */
final readonly class TreeNode
{
    /**
     * @param list<TreeNode> $children
     */
    public function __construct(
        public int $id,
        public array $children = [],
    ) {}
}
