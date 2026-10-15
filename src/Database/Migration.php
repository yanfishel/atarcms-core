<?php

declare(strict_types=1);

namespace AtarCms\Database;

interface Migration
{
    public function up(Connection $db): void;
}
