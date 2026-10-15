<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Content\Field\Field;

/**
 * One tab of the entry form (EntryForm::layout()): the entry's own inputs on it, then the collection's fields. A tab
 * of its own for one field (a slides field) carries that field and is named by its label.
 */
final readonly class FormTab
{
    /**
     * @param string $name "content", "media", "settings", "seo", or "slides-{field}" for a slides field's own tab
     * @param list<string> $builtins the built-in inputs, in order: title, status, published_at, parent, terms, share,
     *                               meta_title, meta_description, slug
     * @param list<Field> $fields the collection's fields on this tab, in config order
     * @param int $errors how many inputs of the tab have an error
     * @param int $count the number of chosen files (the media tab only)
     * @param Field|null $field the field a tab of its own shows (labelled by the field's label), else null
     * @param list<Field> $leadFields the tab's tags fields, shown before the built-in inputs: the tags sit above the
     *                                status like the taxonomy pickers (a user decision)
     */
    public function __construct(
        public string $name,
        public array $builtins,
        public array $fields,
        public int $errors = 0,
        public int $count = 0,
        public ?Field $field = null,
        public array $leadFields = [],
    ) {}
}
