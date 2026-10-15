<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->execute(
            'CREATE TABLE entry_terms (
                entry_id INT UNSIGNED NOT NULL,
                term_id INT UNSIGNED NOT NULL,
                position INT NOT NULL DEFAULT 0,
                entry_position INT NOT NULL DEFAULT 0,
                PRIMARY KEY (entry_id, term_id),
                KEY entry_terms_term_index (term_id, entry_id),
                CONSTRAINT entry_terms_entry_fk FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE CASCADE,
                CONSTRAINT entry_terms_term_fk FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
