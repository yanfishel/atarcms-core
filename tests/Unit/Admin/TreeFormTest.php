<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\TreeForm;
use AtarCms\Content\TreeNode;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class TreeFormTest extends TestCase
{
    public function testParsesTheTreeVersionAndLocale(): void
    {
        $form = TreeForm::parse(self::request(['tree' => '[{"id":1,"children":[{"id":2}]},{"id":3}]', 'version' => str_repeat('a', 40), 'locale' => 'ru']));

        self::assertEquals([new TreeNode(1, [new TreeNode(2)]), new TreeNode(3)], $form->tree);
        self::assertSame([str_repeat('a', 40), 'ru', []], [$form->version, $form->locale, $form->errors]);
    }

    public function testAppliesAnArrowButtonMove(): void
    {
        $form = TreeForm::parse(self::request(['tree' => '[{"id":1},{"id":2}]', 'move' => '2:indent']));

        self::assertEquals([new TreeNode(1, [new TreeNode(2)])], $form->tree);
        self::assertSame([], $form->errors);
        self::assertEquals([new TreeNode(-1), new TreeNode(1)], TreeForm::parse(self::request(['tree' => '[{"id":1},{"id":-1}]', 'move' => '-1:up']))->tree, 'a pinned item of the Sections screen');
    }

    public function testMalformedSubmissionsHaveNoTree(): void
    {
        foreach ([
            ['tree' => 'not json'],
            ['tree' => ['id' => 1]],
            [],
            ['tree' => '[{"id":1}]', 'move' => '1'],
            ['tree' => '[{"id":1}]', 'move' => 'x:up'],
            ['tree' => '[{"id":1}]', 'move' => '2:up'],
            ['tree' => '[{"id":1}]', 'move' => '1:sideways'],
        ] as $body) {
            $form = TreeForm::parse(self::request($body));

            self::assertNull($form->tree, (string) json_encode($body));
            self::assertSame(['tree' => 'validation.tree.invalid'], $form->errors);
        }
        self::assertSame('', TreeForm::parse(self::request(['version' => ['x']]))->version);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function request(array $body): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', 'https://localhost/admin/menus/main/order')->withParsedBody($body);
    }
}
