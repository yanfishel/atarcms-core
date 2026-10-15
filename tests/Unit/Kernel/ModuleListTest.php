<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel;

use AtarCms\Kernel\Module;
use AtarCms\Kernel\ModuleList;
use PHPUnit\Framework\TestCase;

final class ModuleListTest extends TestCase
{
    public function testCollectsAdminTemplatePathsInModuleOrder(): void
    {
        $first = new class extends Module {
            public function adminTemplatePaths(): array
            {
                return ['/a'];
            }
        };
        $second = new class extends Module {
            public function adminTemplatePaths(): array
            {
                return ['/b', '/c'];
            }
        };

        self::assertSame(['/a', '/b', '/c'], new ModuleList([$first, new class extends Module {}, $second])->adminTemplatePaths());
    }
}
