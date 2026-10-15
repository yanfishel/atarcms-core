<?php

declare(strict_types=1);

namespace AtarCms\Content;

/**
 * One /llms.txt section while it is gathered: a collection's or taxonomy's label and its links per locale.
 */
final class LlmsTxtSection
{
    /** @var array<string, list<array{0: string, 1: string}>> locale => [title, absolute URL] */
    public array $links = [];

    public function __construct(public readonly string $label) {}
}
