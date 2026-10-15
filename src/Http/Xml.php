<?php

declare(strict_types=1);

namespace AtarCms\Http;

final class Xml
{
    /**
     * Escapes text for XML element content and attribute values.
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
