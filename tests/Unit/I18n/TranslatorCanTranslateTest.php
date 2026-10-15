<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class TranslatorCanTranslateTest extends TestCase
{
    use TempDirectory;

    public function testFollowsTheFallbackChain(): void
    {
        $this->writeFile('lang/en.php', "<?php return ['a' => 'A', 'b' => ['c' => 'C']];");
        $this->writeFile('lang/ru.php', "<?php return ['d' => 'Д'];");
        $translator = new Translator(new Locales('ru', ['ru', 'en', 'de']), [$this->tempDir() . '/lang'], new MemoryLogger());

        self::assertTrue($translator->canTranslate('ru', 'd'));
        self::assertTrue($translator->canTranslate('de', 'd'), 'falls back to the default locale');
        self::assertTrue($translator->canTranslate('de', 'b.c'), 'falls back to English');
        self::assertFalse($translator->canTranslate('ru', 'missing'));
        self::assertFalse($translator->canTranslate('ru', 'b'), 'a group is not a message');
    }
}
