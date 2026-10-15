<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Http\Exception\ValidationFailed;
use JsonException;

/**
 * The drag-and-drop ordering shared by menus, hierarchical collections and hierarchical taxonomies. The admin posts
 * the whole tree as JSON ([{"id": 1, "children": [...]}, ...]) together with the version it was loaded at;
 * TreeOrder parses it, applies an arrow-button move, checks it against the stored items and turns it into parent ids
 * and sibling positions. Errors are ValidationFailed on the "tree" field. Ids are stored ids, except the negative ids
 * the Sections screen gives the main menu's pinned collections (MainMenuService): no other scope stores them, so
 * placements() refuses them there.
 */
final class TreeOrder
{
    /** The deepest tree any scope may have (EntryUrls builds nested page paths up to this depth). */
    public const int MAX_DEPTH = 32;

    public const int MAX_JSON_LENGTH = 1_000_000;

    /** @var list<string> */
    public const array MOVES = ['up', 'down', 'indent', 'outdent'];

    /**
     * @return list<TreeNode>
     *
     * @throws ValidationFailed validation.tree.invalid
     */
    public static function parse(string $json): array
    {
        if (strlen($json) > self::MAX_JSON_LENGTH) {
            throw self::invalid();
        }
        try {
            $data = json_decode($json, true, 2 * self::MAX_DEPTH + 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalid();
        }

        return self::nodes($data, 0);
    }

    /**
     * Applies an arrow button: "up"/"down" swap the item with its neighbour, "indent" makes it the last child of the
     * sibling above, "outdent" moves it right after its parent. At an edge nothing changes.
     *
     * @param list<TreeNode> $tree
     *
     * @return list<TreeNode>
     *
     * @throws ValidationFailed validation.tree.invalid for an unknown id or direction
     */
    public static function move(array $tree, int $id, string $direction): array
    {
        if (!in_array($direction, self::MOVES, true)) {
            throw self::invalid();
        }

        return self::moveIn($tree, $id, $direction) ?? throw self::invalid();
    }

    /**
     * The parent and sibling position of every node, in tree order. The tree must hold every id of $ids exactly
     * once and nothing else, at most $maxDepth levels deep.
     *
     * @param list<TreeNode> $tree
     * @param list<int> $ids the ids stored in the scope being ordered
     *
     * @return array<int, array{parentId: int|null, position: int}>
     *
     * @throws ValidationFailed validation.tree.invalid or validation.tree.depth
     */
    public static function placements(array $tree, array $ids, int $maxDepth): array
    {
        $expected = array_fill_keys($ids, true);
        $placements = [];
        self::place($tree, null, 0, $expected, $maxDepth, $placements);
        if (count($placements) !== count($expected)) {
            throw self::invalid();
        }

        return $placements;
    }

    /**
     * A token for the stored order: any change of an item's parent or position, or of the set of items, changes it.
     *
     * @param list<TreeItem> $items
     */
    public static function version(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[$item->id] = $item->id . ':' . ($item->parentId ?? 0) . ':' . $item->position;
        }
        ksort($lines);

        return sha1(implode("\n", $lines));
    }

    /**
     * The stored items as a tree. Siblings keep the given order (load them by position); an item whose parent is not
     * among them is a root, and a parent cycle is broken at the first item reached.
     *
     * @param list<TreeItem> $items
     *
     * @return list<TreeNode>
     */
    public static function nest(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[$item->id] = true;
        }
        $children = [];
        foreach ($items as $item) {
            $parent = $item->parentId !== null && $item->parentId !== $item->id && isset($ids[$item->parentId]) ? $item->parentId : 0;
            $children[$parent][] = $item->id;
        }
        $seen = [];
        $roots = self::branch($children, 0, $seen);
        foreach ($items as $item) {
            // Items in a parent cycle are never reached from a root.
            if (!isset($seen[$item->id])) {
                $seen[$item->id] = true;
                $roots[] = new TreeNode($item->id, self::branch($children, $item->id, $seen));
            }
        }

        return $roots;
    }

    /**
     * @template T of TreeItem
     *
     * @param list<T> $items
     * @param array<int, array{parentId: int|null, position: int}> $placements
     *
     * @return list<T> the items whose parent or position differs from $placements, in the order of $items
     */
    public static function changed(array $items, array $placements): array
    {
        $changed = [];
        foreach ($items as $item) {
            $placement = $placements[$item->id] ?? null;
            if ($placement !== null && ($placement['parentId'] !== $item->parentId || $placement['position'] !== $item->position)) {
                $changed[] = $item;
            }
        }

        return $changed;
    }

    /**
     * @param list<TreeNode> $tree
     */
    public static function toJson(array $tree): string
    {
        return json_encode(self::toArray($tree), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<TreeNode>
     */
    private static function nodes(mixed $data, int $depth): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw self::invalid();
        }
        if ($data !== [] && $depth >= self::MAX_DEPTH) {
            throw self::invalid();
        }
        $nodes = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                throw self::invalid();
            }
            $id = $item['id'] ?? null;
            if (!is_int($id) || $id === 0) {
                throw self::invalid();
            }
            $nodes[] = new TreeNode($id, self::nodes($item['children'] ?? [], $depth + 1));
        }

        return $nodes;
    }

    /**
     * @param list<TreeNode> $siblings
     *
     * @return list<TreeNode>|null the new siblings, or null when $id is neither among them nor below them
     */
    private static function moveIn(array $siblings, int $id, string $direction): ?array
    {
        foreach ($siblings as $index => $node) {
            if ($node->id === $id) {
                return self::moveSibling($siblings, $index, $direction);
            }
            foreach ($node->children as $childIndex => $child) {
                if ($child->id === $id && $direction === 'outdent') {
                    $children = $node->children;
                    array_splice($children, $childIndex, 1);
                    $result = $siblings;
                    array_splice($result, $index, 1, [new TreeNode($node->id, $children), $child]);

                    return $result;
                }
            }
            $children = self::moveIn($node->children, $id, $direction);
            if ($children !== null) {
                $result = $siblings;
                $result[$index] = new TreeNode($node->id, $children);

                return array_values($result);
            }
        }

        return null;
    }

    /**
     * @param list<TreeNode> $siblings
     *
     * @return list<TreeNode>
     */
    private static function moveSibling(array $siblings, int $index, string $direction): array
    {
        $node = $siblings[$index];
        $last = count($siblings) - 1;
        if ($direction === 'up' && $index > 0) {
            $siblings[$index] = $siblings[$index - 1];
            $siblings[$index - 1] = $node;
        } elseif ($direction === 'down' && $index < $last) {
            $siblings[$index] = $siblings[$index + 1];
            $siblings[$index + 1] = $node;
        } elseif ($direction === 'indent' && $index > 0) {
            $previous = $siblings[$index - 1];
            $siblings[$index - 1] = new TreeNode($previous->id, [...$previous->children, $node]);
            unset($siblings[$index]);
        }
        // "outdent" of a top-level item changes nothing; a nested item is handled by its parent in moveIn().

        return array_values($siblings);
    }

    /**
     * @param list<TreeNode> $nodes
     * @param array<int, true> $expected
     * @param array<int, array{parentId: int|null, position: int}> $placements
     */
    private static function place(array $nodes, ?int $parentId, int $depth, array $expected, int $maxDepth, array &$placements): void
    {
        foreach ($nodes as $position => $node) {
            if (!isset($expected[$node->id]) || isset($placements[$node->id])) {
                throw self::invalid();
            }
            if ($depth >= $maxDepth) {
                throw new ValidationFailed(['tree' => 'validation.tree.depth'], ['tree' => ['max' => $maxDepth]]);
            }
            $placements[$node->id] = ['parentId' => $parentId, 'position' => $position];
            self::place($node->children, $node->id, $depth + 1, $expected, $maxDepth, $placements);
        }
    }

    /**
     * @param array<int, list<int>> $children parent id (0 for roots) => child ids in order
     * @param array<int, true> $seen
     *
     * @return list<TreeNode>
     */
    private static function branch(array $children, int $parent, array &$seen): array
    {
        $nodes = [];
        foreach ($children[$parent] ?? [] as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $nodes[] = new TreeNode($id, self::branch($children, $id, $seen));
        }

        return $nodes;
    }

    /**
     * @param list<TreeNode> $nodes
     *
     * @return list<array{id: int, children: list<mixed>}>
     */
    private static function toArray(array $nodes): array
    {
        return array_map(static fn(TreeNode $node): array => ['id' => $node->id, 'children' => self::toArray($node->children)], $nodes);
    }

    private static function invalid(): ValidationFailed
    {
        return new ValidationFailed(['tree' => 'validation.tree.invalid']);
    }
}
