<?php

declare(strict_types=1);

namespace AtarCms\Admin\Menu;

use AtarCms\Content\Menu\MenuItemInput;

/**
 * @phpstan-type MenuItemFormValues array{label: string, target_type: string, entry_id: string, term_id: string, url: string, new_tab: bool, parent_id: string}
 */
final readonly class MenuItemFormData
{
    /**
     * @param MenuItemFormValues $values the submitted values, to re-render the form
     * @param array<string, string> $errors field => translation key, for malformed values
     */
    public function __construct(
        public MenuItemInput $input,
        public array $values,
        public string $version,
        public string $locale,
        public array $errors,
    ) {}
}
