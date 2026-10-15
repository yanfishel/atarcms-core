<?php

declare(strict_types=1);

namespace AtarCms\Search;

use AtarCms\Content\Entry;
use AtarCms\Content\Field\RichText;
use AtarCms\Content\Field\RichTextType;
use AtarCms\Content\Field\TextareaType;
use AtarCms\Content\Field\TextType;

/**
 * The searchable text of an entry: the values of its text, textarea and rich-text fields (rich text as plain text),
 * in field order. The search index stores it, and a result's description is cut from its first value.
 */
final class EntryText
{
    /**
     * @return list<string> the non-empty values
     */
    public static function of(Entry $entry): array
    {
        $texts = [];
        foreach ($entry->collection->fields as $name => $field) {
            $type = $field->type;
            if (!$type instanceof TextType && !$type instanceof TextareaType && !$type instanceof RichTextType) {
                continue;
            }
            $value = $entry->field($name);
            $text = $value instanceof RichText ? $value->text() : (is_string($value) ? trim($value) : '');
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
