<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Content\EntryService;

/**
 * Parses the "parent_id" and "position" fields of the entry admin form, and the "parent_id" field alone for the term
 * form (terms have no position input: their order is set on the order screens).
 */
final class PositionParentFields
{
    /**
     * @return array{parentId: int|null, position: int, errors: array<string, string>}
     */
    public static function parse(string $rawParentId, string $rawPosition, string $parentErrorKey): array
    {
        $parent = self::parseParent($rawParentId, $parentErrorKey);
        $errors = $parent['errors'];
        $position = 0;
        $rawPosition = trim($rawPosition);
        if ($rawPosition !== '') {
            $n = (int) $rawPosition;
            if (preg_match('/^-?\d{1,10}$/', $rawPosition) === 1 && $n >= EntryService::MIN_POSITION && $n <= EntryService::MAX_POSITION) {
                $position = $n;
            } else {
                $errors = ['position' => 'validation.field.number', ...$errors];
            }
        }

        return ['parentId' => $parent['parentId'], 'position' => $position, 'errors' => $errors];
    }

    /**
     * @return array{parentId: int|null, errors: array<string, string>}
     */
    public static function parseParent(string $rawParentId, string $parentErrorKey): array
    {
        $rawParentId = trim($rawParentId);
        if ($rawParentId === '') {
            return ['parentId' => null, 'errors' => []];
        }
        if (preg_match('/^[1-9]\d{0,9}$/', $rawParentId) === 1) {
            return ['parentId' => (int) $rawParentId, 'errors' => []];
        }

        return ['parentId' => null, 'errors' => ['parent_id' => $parentErrorKey]];
    }
}
