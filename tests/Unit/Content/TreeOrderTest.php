<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Term;
use AtarCms\Content\TreeNode;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\Exception\ValidationFailed;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TreeOrderTest extends TestCase
{
    public function testParsesAndSerializesTheSubmittedTree(): void
    {
        $tree = TreeOrder::parse('[{"id":1,"children":[{"id":2},{"id":3,"children":[]}]},{"id":4,"children":[]}]');

        self::assertEquals([new TreeNode(1, [new TreeNode(2), new TreeNode(3)]), new TreeNode(4)], $tree);
        self::assertSame(
            '[{"id":1,"children":[{"id":2,"children":[]},{"id":3,"children":[]}]},{"id":4,"children":[]}]',
            TreeOrder::toJson($tree),
        );
        self::assertSame([], TreeOrder::parse('[]'));
        self::assertSame('[]', TreeOrder::toJson([]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTrees(): iterable
    {
        yield 'not json' => ['[{'];
        yield 'empty string' => [''];
        yield 'an object at the top' => ['{"id":1}'];
        yield 'a string id' => ['[{"id":"1"}]'];
        yield 'a float id' => ['[{"id":1.5}]'];
        yield 'a zero id' => ['[{"id":0}]'];

        yield 'no id' => ['[{"children":[]}]'];
        yield 'children not a list' => ['[{"id":1,"children":{"id":2}}]'];
        yield 'a scalar node' => ['[1]'];
        yield 'too deep' => [str_repeat('[{"id":1,"children":', 40) . '[]' . str_repeat('}]', 40)];
        yield 'too long' => ['[' . str_repeat(' ', TreeOrder::MAX_JSON_LENGTH) . ']'];
    }

    #[DataProvider('malformedTrees')]
    public function testRejectsMalformedTrees(string $json): void
    {
        self::assertTreeError('validation.tree.invalid', static fn() => TreeOrder::parse($json));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function moves(): iterable
    {
        // The tree is "1(2 3) 4": item 1 with the children 2 and 3, then item 4.
        yield '4 up' => [4, 'up', '4 1(2 3)'];
        yield '1 up at the top edge' => [1, 'up', '1(2 3) 4'];
        yield '1 down' => [1, 'down', '4 1(2 3)'];
        yield '4 down at the bottom edge' => [4, 'down', '1(2 3) 4'];
        yield '3 up among children' => [3, 'up', '1(3 2) 4'];
        yield '2 down among children' => [2, 'down', '1(3 2) 4'];
        yield '4 indent' => [4, 'indent', '1(2 3 4)'];
        yield '3 indent' => [3, 'indent', '1(2(3)) 4'];
        yield '2 indent without a previous sibling' => [2, 'indent', '1(2 3) 4'];
        yield '1 indent without a previous sibling' => [1, 'indent', '1(2 3) 4'];
        yield '2 outdent' => [2, 'outdent', '1(3) 2 4'];
        yield '3 outdent' => [3, 'outdent', '1(2) 3 4'];
        yield '1 outdent at the top level' => [1, 'outdent', '1(2 3) 4'];
    }

    #[DataProvider('moves')]
    public function testMovesAnItemLikeTheArrowButtons(int $id, string $direction, string $expected): void
    {
        $tree = TreeOrder::parse('[{"id":1,"children":[{"id":2},{"id":3}]},{"id":4}]');

        self::assertSame($expected, self::shape(TreeOrder::move($tree, $id, $direction)));
    }

    public function testOutdentsFromDeepLevelsAndRejectsUnknownMoves(): void
    {
        $tree = TreeOrder::parse('[{"id":1,"children":[{"id":2,"children":[{"id":3}]}]}]');

        self::assertSame('1(2 3)', self::shape(TreeOrder::move($tree, 3, 'outdent')));
        self::assertTreeError('validation.tree.invalid', static fn() => TreeOrder::move($tree, 9, 'up'));
        self::assertTreeError('validation.tree.invalid', static fn() => TreeOrder::move($tree, 1, 'left'));
    }

    public function testTurnsTheTreeIntoParentsAndPositions(): void
    {
        $placements = TreeOrder::placements(TreeOrder::parse('[{"id":4},{"id":1,"children":[{"id":3},{"id":2}]}]'), [1, 2, 3, 4], 2);

        self::assertSame([
            4 => ['parentId' => null, 'position' => 0],
            1 => ['parentId' => null, 'position' => 1],
            3 => ['parentId' => 1, 'position' => 0],
            2 => ['parentId' => 1, 'position' => 1],
        ], $placements);
        self::assertSame([], TreeOrder::placements([], [], 3));
    }

    public function testTheTreeMustHoldExactlyTheStoredItems(): void
    {
        $ids = [1, 2, 3];

        self::assertTreeError('validation.tree.invalid', static fn() => TreeOrder::placements(TreeOrder::parse('[{"id":1},{"id":2},{"id":3},{"id":9}]'), $ids, 3));
        self::assertTreeError('validation.tree.invalid', static fn() => TreeOrder::placements(TreeOrder::parse('[{"id":1},{"id":2}]'), $ids, 3));
        // Negative ids are the pinned items of the Sections screen (MainMenuService): no stored scope has them.
        self::assertTreeError('validation.tree.invalid', static fn() => TreeOrder::placements(TreeOrder::parse('[{"id":1},{"id":2},{"id":3},{"id":-1}]'), $ids, 3));
        // A duplicate is the only way to write a cycle ("1 inside 2 inside 1") in a nested tree.
        self::assertTreeError('validation.tree.invalid', static fn() => TreeOrder::placements(TreeOrder::parse('[{"id":1,"children":[{"id":2,"children":[{"id":1}]}]},{"id":3}]'), $ids, 5));
        self::assertTreeError(
            'validation.tree.depth',
            static fn() => TreeOrder::placements(TreeOrder::parse('[{"id":1,"children":[{"id":2,"children":[{"id":3}]}]}]'), $ids, 2),
            ['max' => 2],
        );
    }

    public function testTheVersionChangesWithEveryParentOrPosition(): void
    {
        $items = [self::item(1), self::item(2, 1, 0), self::item(3, 1, 1)];
        $version = TreeOrder::version($items);

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $version);
        self::assertSame($version, TreeOrder::version(array_reverse($items)), 'independent of the load order');
        self::assertNotSame($version, TreeOrder::version([self::item(1), self::item(2, 1, 1), self::item(3, 1, 0)]));
        self::assertNotSame($version, TreeOrder::version([self::item(1), self::item(2, null, 0), self::item(3, 1, 1)]));
        self::assertNotSame($version, TreeOrder::version([self::item(1), self::item(2, 1, 0)]));
    }

    public function testNestsStoredItemsAndBreaksBrokenParents(): void
    {
        $tree = TreeOrder::nest([
            self::item(5),
            self::item(2, 1),
            self::item(1),
            self::item(3, 1),
            self::item(7, 99),
            self::item(6, 6),
            self::item(8, 9),
            self::item(9, 8),
        ]);

        self::assertSame('5 1(2 3) 7 6 8(9)', self::shape($tree));
    }

    public function testListsTheItemsThatMoved(): void
    {
        $items = [self::item(1), self::item(2, 1, 0), self::item(3, null, 1)];

        $moved = TreeOrder::changed($items, [
            1 => ['parentId' => null, 'position' => 0],
            2 => ['parentId' => null, 'position' => 1],
            3 => ['parentId' => null, 'position' => 2],
        ]);

        self::assertSame([2, 3], array_map(static fn(Term $term): int => $term->id, $moved));
    }

    /**
     * "1(2 3) 4": ids in order, children in parentheses.
     *
     * @param list<TreeNode> $nodes
     */
    private static function shape(array $nodes): string
    {
        return implode(' ', array_map(
            static fn(TreeNode $node): string => $node->id . ($node->children === [] ? '' : '(' . self::shape($node->children) . ')'),
            $nodes,
        ));
    }

    private static function item(int $id, ?int $parentId = null, int $position = 0): Term
    {
        $now = new DateTimeImmutable('2026-09-28 12:00:00');

        return new Term($id, 'topics', 'en', str_repeat('a', 32), 't' . $id, 'T' . $id, $parentId, $position, $now, $now);
    }

    /**
     * @param array<string, int|float|string> $params
     */
    private static function assertTreeError(string $key, Closure $action, array $params = []): void
    {
        try {
            $action();
        } catch (ValidationFailed $e) {
            self::assertSame(['tree' => $key], $e->errors);
            self::assertSame($params === [] ? [] : ['tree' => $params], $e->params);

            return;
        }
        self::fail('Expected ValidationFailed');
    }
}
