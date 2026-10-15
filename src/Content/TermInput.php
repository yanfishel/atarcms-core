<?php

declare(strict_types=1);

namespace AtarCms\Content;

/**
 * The submitted state of a term, before validation.
 */
final readonly class TermInput
{
    /**
     * @param string $slug '' generates one from the name on create and keeps the current slug on update
     * @param int|null $position null puts a new term last among its siblings and keeps an existing one where it is (last
     *                           among the new siblings when its parent changes)
     * @param string $metaTitle the title for search engines (sections only; may hold "{title}"); '' = none
     * @param string $metaDescription the description for search engines (sections only); '' = none
     */
    public function __construct(
        public string $name,
        public string $slug = '',
        public ?int $parentId = null,
        public ?int $position = null,
        public string $metaTitle = '',
        public string $metaDescription = '',
    ) {}
}
