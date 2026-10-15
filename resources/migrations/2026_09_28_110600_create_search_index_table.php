<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

/**
 * The site search index: one row per entry of a searchable collection, with its title and text as normalised stems
 * (TextNormalizer), searched through InnoDB FULLTEXT indexes. Deleting an entry deletes its row.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        // InnoDB reads this when a FULLTEXT index is created: without it the built-in English stopword list would
        // leave words like "about" or "the" out of the indexes for good.
        $db->execute('SET SESSION innodb_ft_enable_stopword = 0');

        try {
            $db->execute(
                'CREATE TABLE search_index (
                    entry_id INT UNSIGNED NOT NULL PRIMARY KEY,
                    collection VARCHAR(32) NOT NULL,
                    locale VARCHAR(12) NOT NULL,
                    title TEXT NOT NULL,
                    body MEDIUMTEXT NOT NULL,
                    KEY search_index_scope (locale, collection),
                    FULLTEXT KEY search_index_title (title),
                    FULLTEXT KEY search_index_body (body),
                    FULLTEXT KEY search_index_all (title, body),
                    CONSTRAINT search_index_entry_fk FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            );
        } finally {
            $db->execute('SET SESSION innodb_ft_enable_stopword = DEFAULT');
        }
    }
};
