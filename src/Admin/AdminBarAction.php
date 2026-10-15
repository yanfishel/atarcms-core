<?php

declare(strict_types=1);

namespace AtarCms\Admin;

/**
 * One button of the public site's admin bar (AdminBar): where it goes, its accessible name (also the tooltip), the
 * admin icon name it stands for (a Lucide symbol; a theme draws it from its own sprite), whether it adds something
 * (a plus over the icon) and a count to show over it (unread messages; null for none). An action without a URL is
 * inactive: it keeps its place and its label says why (Edit on a page with nothing the user may edit).
 */
final readonly class AdminBarAction
{
    public const string DASHBOARD = 'dashboard';

    public const string ADD = 'add';

    public const string EDIT = 'edit';

    public const string MESSAGES = 'messages';

    public function __construct(
        public string $kind,
        public ?string $url,
        public string $label,
        public string $icon,
        public ?string $badge = null,
    ) {}
}
