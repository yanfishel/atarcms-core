<?php

declare(strict_types=1);

use AtarCms\Database\Connection;
use AtarCms\Database\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->execute(
            'CREATE TABLE terms (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                taxonomy VARCHAR(32) NOT NULL,
                locale VARCHAR(12) NOT NULL,
                translation_group CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                slug VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name VARCHAR(200) NOT NULL,
                parent_id INT UNSIGNED NULL,
                position INT NOT NULL DEFAULT 0,
                meta_title VARCHAR(255) NULL,
                meta_description VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY terms_taxonomy_locale_slug_unique (taxonomy, locale, slug),
                UNIQUE KEY terms_group_locale_unique (translation_group, locale),
                KEY terms_listing_index (taxonomy, locale, position),
                KEY terms_name_index (taxonomy, locale, name),
                CONSTRAINT terms_parent_fk FOREIGN KEY (parent_id) REFERENCES terms (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
