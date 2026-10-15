<?php

declare(strict_types=1);

namespace AtarCms\Content\ShareImage;

/**
 * What ShareImages::sync() did to an entry's generated share image.
 */
enum ShareImageOutcome: string
{
    /** A new picture was drawn (its inputs changed, or it was missing). */
    case Made = 'made';

    /** The stored picture still matches its inputs. */
    case Unchanged = 'unchanged';

    /** The entry no longer qualifies (no main photo or no title to draw) and its picture was deleted. */
    case Removed = 'removed';

    /** The entry does not qualify and had no picture. */
    case None = 'none';

    /** Drawing failed (logged); the entry is left without a picture. */
    case Failed = 'failed';
}
