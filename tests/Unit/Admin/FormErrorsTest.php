<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\FlashMessage;
use AtarCms\Admin\FormErrors;
use PHPUnit\Framework\TestCase;

final class FormErrorsTest extends TestCase
{
    public function testSplitsPlacedErrorsFromFieldlessOnesInTheirOrder(): void
    {
        [$fields, $toasts] = FormErrors::split(
            ['term' => 'validation.term.stale', 'name' => 'validation.required', 'position' => 'validation.position', 'slug' => 'validation.slug'],
            ['position' => ['max' => 3], 'name' => ['x' => 1]],
            ['name', 'slug', 'parent_id'],
        );

        self::assertSame(['name' => 'validation.required', 'slug' => 'validation.slug'], $fields);
        self::assertEquals([
            new FlashMessage('error', 'validation.term.stale', []),
            new FlashMessage('error', 'validation.position', ['max' => 3]),
        ], $toasts);
    }

    public function testNoErrorsGiveNothing(): void
    {
        self::assertSame([[], []], FormErrors::split([], [], ['name']));
    }
}
