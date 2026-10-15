<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Preset\PresetChange;
use PHPUnit\Framework\TestCase;

/**
 * The console lines of PresetPages::sync() ("bin/atar migrate"): plain words, never a translation key.
 */
final class PresetChangeTest extends TestCase
{
    public function testEveryRefusalIsExplainedInPlainWords(): void
    {
        self::assertSame('Preset page created: pages/privacy (ru)', new PresetChange('pages', 'privacy', 'ru', 'created', 1)->line());
        self::assertStringStartsWith('Preset page adopted', new PresetChange('pages', 'privacy', 'ru', 'adopted', 1)->line());
        $keys = [
            'validation.slug.reserved', 'validation.slug.taken', 'validation.entry.preset_adopt', 'validation.locale.exists',
            'validation.locale.unsupported', 'validation.single.exists', 'validation.media.gone', null,
        ];
        foreach ($keys as $key) {
            $line = new PresetChange('pages', 'privacy', 'he', 'refused', reason: $key)->line();
            self::assertStringStartsWith('Preset page NOT created: pages/privacy (he): ', $line);
            self::assertStringNotContainsString('validation.', $line, (string) $key);
            self::assertGreaterThan(60, strlen($line), (string) $key);
        }
    }
}
