<?php

declare(strict_types=1);

namespace AtarCms\Media\Cleanup;

/**
 * The outcome of MediaStorage::removeStored().
 */
enum Removal
{
    /** Deleted, or gone already. */
    case Removed;

    /** Written again after the inventory (a share image's name is deterministic): left in place, not a failure. */
    case Kept;

    /** Could not be deleted, was replaced by something else, or lies outside the directory its name claims. */
    case Failed;
}
