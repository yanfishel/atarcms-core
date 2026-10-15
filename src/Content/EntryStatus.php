<?php

declare(strict_types=1);

namespace AtarCms\Content;

enum EntryStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Scheduled = 'scheduled';

    /** Statuses that are public once published_at has passed. */
    public const array PUBLIC = ['published', 'scheduled'];
}
