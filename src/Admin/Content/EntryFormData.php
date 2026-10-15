<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Content\EntryInput;

/**
 * @phpstan-type EntryFormValues array{title: string, slug: string, status: string, published_at: string, parent_id: string, position: string, meta_title: string, meta_description: string, fields: array<string, mixed>, terms: array<string, list<string>>}
 */
final readonly class EntryFormData
{
    /**
     * @param EntryFormValues $values the submitted values, to re-render the form
     * @param array<string, string> $errors field => translation key, for malformed core values
     */
    public function __construct(
        public EntryInput $input,
        public array $values,
        public string $version,
        public string $locale,
        public array $errors,
    ) {}
}
