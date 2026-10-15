<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Auth\AuditEntry;

/**
 * The readable name of an activity history row's item, from what the services record: an entry's title, a term's
 * name, a menu item's label, a file's name, a user's e-mail (or the actor's, for a sign-in), a message's sender, and the name a reorder
 * records as its id (a collection, taxonomy or menu location). Null when the row has no such name.
 */
final class AuditSubject
{
    /** Item kind => the meta key that holds its name. */
    private const array NAMES = ['entry' => 'title', 'term' => 'name', 'menu_item' => 'label', 'media' => 'name', 'media_folder' => 'name', 'user' => 'email', 'message' => 'name'];

    /** Item kinds whose id is their name. */
    private const array NAMED_BY_ID = ['collection', 'taxonomy', 'menu'];

    public static function name(AuditEntry $entry): ?string
    {
        if ($entry->subjectType === null) {
            return null;
        }
        if (in_array($entry->subjectType, self::NAMED_BY_ID, true)) {
            return $entry->subjectId;
        }
        $name = $entry->meta[self::NAMES[$entry->subjectType] ?? ''] ?? null;
        if (is_string($name) && trim($name) !== '') {
            return $name;
        }
        if ($entry->subjectType === 'user' && $entry->userId !== null && $entry->subjectId === (string) $entry->userId) {
            return $entry->userEmail;
        }

        return null;
    }
}
