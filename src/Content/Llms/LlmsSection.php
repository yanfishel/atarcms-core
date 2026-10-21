<?php

declare(strict_types=1);

namespace AtarCms\Content\Llms;

/**
 * One section of /llms.txt and /llms-full.txt while it is gathered: a collection's or taxonomy's label and its links
 * per locale.
 */
final class LlmsSection
{
    /** @var array<string, list<LlmsLink>> locale => links */
    public array $links = [];

    public function __construct(public readonly string $label) {}
}
