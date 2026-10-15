<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

/**
 * The two columns of the entry form, each a list of tabs: main (wide) and side (narrow), and the errors of inputs
 * the form does not show (a crafted post), which it lists above the tabs.
 */
final readonly class FormLayout
{
    /**
     * @param list<FormTab> $main
     * @param list<FormTab> $side
     * @param list<string> $unplaced the error keys that no tab shows, in error order
     */
    public function __construct(
        public array $main,
        public array $side,
        public array $unplaced = [],
    ) {}

    /**
     * @return array{main: list<FormTab>, side: list<FormTab>}
     */
    public function columns(): array
    {
        return ['main' => $this->main, 'side' => $this->side];
    }
}
