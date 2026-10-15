<?php

declare(strict_types=1);

// Warning: any role granted "users.manage" can create users and assign them roles, limited to roles whose
// permissions its own role covers.
//
// Role => label (plain text, or a translation key such as "admin.roles.admin" for the admin's languages) and
// permission patterns. "*" matches everything; otherwise each "*" matches one segment, e.g. "entries.*.edit"
// allows editing entries of every collection.
//
// Entry permissions: entries.{collection}.{view|create|edit|edit_others|publish|delete}. Without
// "edit_others" a user edits and deletes only the entries they created; without "publish" they can
// neither publish nor edit published or scheduled entries (the default "author" role).
return [
    'admin' => ['label' => 'admin.roles.admin', 'permissions' => ['*']],
    'editor' => ['label' => 'admin.roles.editor', 'permissions' => ['entries.*.*', 'media.*', 'taxonomies.manage', 'menus.manage', 'messages.manage']],
    'author' => ['label' => 'admin.roles.author', 'permissions' => ['entries.*.view', 'entries.*.create', 'entries.*.edit', 'media.upload']],
];
