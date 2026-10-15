<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Content\TreeNode;

/**
 * A parsed tree editor submission.
 */
final readonly class TreeFormData
{
    /**
     * @param list<TreeNode>|null $tree null when the submission is malformed
     * @param array<string, string> $errors field => translation key
     */
    public function __construct(
        public ?array $tree,
        public string $version,
        public string $locale,
        public array $errors,
    ) {}
}
