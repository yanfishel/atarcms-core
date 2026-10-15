<?php

declare(strict_types=1);

namespace AtarCms\Admin\Taxonomy;

use AtarCms\Content\TermInput;

/**
 * @phpstan-type TermFormValues array{name: string, slug: string, parent_id: string, meta_title: string, meta_description: string}
 */
final readonly class TermFormData
{
    /**
     * @param TermFormValues $values the submitted values, to re-render the form
     * @param array<string, string> $errors field => translation key, for malformed values
     */
    public function __construct(
        public TermInput $input,
        public array $values,
        public string $version,
        public string $locale,
        public array $errors,
    ) {}
}
