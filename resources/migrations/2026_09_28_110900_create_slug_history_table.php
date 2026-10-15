<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        // The previous slugs of published entries and of terms (SlugHistoryRepository): an old address answers 301 to
        // the current one. A slug of one collection or taxonomy (scope) and locale names one owner; deleting the
        // owner deletes its history.
        $db->execute(
            'CREATE TABLE slug_history (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                kind VARCHAR(8) NOT NULL,
                scope VARCHAR(32) NOT NULL,
                locale VARCHAR(12) NOT NULL,
                slug VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                entry_id INT UNSIGNED NULL,
                term_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY slug_history_unique (kind, scope, locale, slug),
                CONSTRAINT slug_history_entry_fk FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE CASCADE,
                CONSTRAINT slug_history_term_fk FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
