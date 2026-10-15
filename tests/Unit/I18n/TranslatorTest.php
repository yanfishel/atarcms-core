<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\ConfigException;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Stringable;

final class TranslatorTest extends TestCase
{
    use TempDirectory;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        $this->writeFile('core/en.php', <<<'PHP'
            <?php return [
                'admin' => ['title' => 'Admin', 'welcome' => 'Welcome, {name}.'],
                'users' => ['count' => '{count, plural, one {# user} other {# users}}'],
                'plain' => "Don't {panic",
                'only_en' => 'English only',
                'http' => ['status' => [404 => 'Not Found']],
            ];
            PHP);
        $this->writeFile('core/ru.php', <<<'PHP'
            <?php return [
                'admin' => ['title' => 'Админка', 'welcome' => 'Добро пожаловать, {name}.'],
                'users' => ['count' => '{count, plural, one {# пользователь} few {# пользователя} many {# пользователей} other {# пользователя}}'],
                'only_default' => 'Только по-русски',
            ];
            PHP);
        $this->writeFile('theme/ru.php', "<?php return ['admin' => ['title' => 'Панель']];");
    }

    public function testTranslatesNestedKeysWithParamsAndPlurals(): void
    {
        $t = $this->translator('en');

        self::assertSame('Admin', $t->translate('en', 'admin.title'));
        self::assertSame('Welcome, Ann <b>.', $t->translate('en', 'admin.welcome', ['name' => 'Ann <b>']));
        self::assertSame('1 user', $t->translate('en', 'users.count', ['count' => 1]));
        self::assertSame('5 users', $t->translate('en', 'users.count', ['count' => 5]));
        self::assertSame('3 пользователя', $t->translate('ru', 'users.count', ['count' => 3]));
        self::assertSame('5 пользователей', $t->translate('ru', 'users.count', ['count' => 5]));
        self::assertSame('21 пользователь', $t->translate('ru', 'users.count', ['count' => 21]));
        self::assertSame('Not Found', $t->translate('en', 'http.status.404'));
    }

    /**
     * In a right-to-left language an inserted name keeps its own direction: "Hello!" or "Русский" inside a Hebrew
     * sentence is isolated (FIRST STRONG ISOLATE … POP DIRECTIONAL ISOLATE). Numbers and plural or format arguments
     * are left alone, and left-to-right languages are unchanged.
     */
    public function testRightToLeftMessagesIsolateInsertedText(): void
    {
        $this->writeFile('core/he.php', <<<'PHP'
            <?php return [
                'admin' => ['welcome' => 'שלום, {name}.'],
                'users' => ['count' => '{count, plural, one {משתמש אחד} other {# משתמשים}}'],
                'mixed' => '{name}: {id} · {size, number}',
            ];
            PHP);
        $t = $this->translator('en');

        self::assertSame("שלום, \u{2068}Ann!\u{2069}.", $t->translate('he', 'admin.welcome', ['name' => 'Ann!']));
        self::assertSame('5 משתמשים', $t->translate('he', 'users.count', ['count' => '5']));
        self::assertSame("\u{2068}Русский\u{2069}: 7 · 1,024", $t->translate('he', 'mixed', ['name' => 'Русский', 'id' => '7', 'size' => 1024]));
        self::assertSame('Welcome, Ann!.', $t->translate('en', 'admin.welcome', ['name' => 'Ann!']));
    }

    public function testStringableParamsAreFormatted(): void
    {
        $name = new class implements Stringable {
            public function __toString(): string
            {
                return 'Ann';
            }
        };

        self::assertSame('Welcome, Ann.', $this->translator('en')->translate('en', 'admin.welcome', ['name' => $name]));
    }

    public function testThemeOverridesCore(): void
    {
        self::assertSame('Панель', $this->translator('en')->translate('ru', 'admin.title'));
    }

    public function testFallsBackToDefaultThenEnglishThenKey(): void
    {
        $t = $this->translator('ru');

        self::assertSame('Только по-русски', $t->translate('de', 'only_default'));
        self::assertSame('English only', $t->translate('ru', 'only_en'));
        self::assertSame('missing.key', $t->translate('ru', 'missing.key'));
        self::assertSame([], $this->logger->records, 'misses are only reported in debug mode');
    }

    public function testReportsMissesOncePerLocaleAndKeyInDebugMode(): void
    {
        $t = $this->translator('ru', reportMisses: true);

        $t->translate('ru', 'only_en');
        $t->translate('ru', 'only_en');
        $t->translate('ru', 'missing.key');

        self::assertCount(2, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertSame(['locale' => 'ru', 'key' => 'only_en'], $this->logger->records[0]['context']);
    }

    public function testBrokenPatternReturnsRawTextAndLogs(): void
    {
        $t = $this->translator('en');

        self::assertSame("Don't {panic", $t->translate('en', 'plain', ['x' => 1]));
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertStringContainsString('Invalid translation', $this->logger->records[0]['message']);
    }

    public function testInvalidParamsAreReplacedWithTheirTypeAndLogged(): void
    {
        $t = $this->translator('en');

        self::assertSame('Welcome, array.', $t->translate('en', 'admin.welcome', ['name' => ['x']]));
        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertStringContainsString('Invalid translation parameter', $this->logger->records[0]['message']);
        self::assertSame(['param' => 'name', 'key' => 'admin.welcome'], $this->logger->records[0]['context']);

        self::assertSame('Welcome, stdClass.', $t->translate('en', 'admin.welcome', ['name' => new \stdClass()]));
    }

    public function testMissingParamLeavesThePlaceholder(): void
    {
        self::assertSame('Welcome, {name}.', $this->translator('en')->translate('en', 'admin.welcome'));
    }

    public function testPlainPatternsAreReturnedVerbatim(): void
    {
        $this->writeFile('core/en.php', "<?php return ['a' => \"Don't panic\"];");

        self::assertSame("Don't panic", $this->translator('en')->translate('en', 'a'));
    }

    public function testHas(): void
    {
        $t = $this->translator('en');

        self::assertTrue($t->has('ru', 'admin.title'));
        self::assertFalse($t->has('ru', 'only_en'));
    }

    public function testIgnoresLocalesThatAreNotCodes(): void
    {
        $this->writeFile('evil.php', "<?php return ['admin' => ['title' => 'pwned']];");

        self::assertSame('Admin', $this->translator('en')->translate('../evil', 'admin.title'));
    }

    public function testRejectsFilesThatDoNotReturnArrays(): void
    {
        $this->writeFile('core/en.php', '<?php return "nope";');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('en.php must return an array');

        $this->translator('en')->translate('en', 'admin.title');
    }

    public function testRejectsNonStringMessages(): void
    {
        $this->writeFile('core/en.php', "<?php return ['admin' => ['title' => 5]];");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"admin.title"');

        $this->translator('en')->translate('en', 'admin.title');
    }

    private function translator(string $default, bool $reportMisses = false): Translator
    {
        return new Translator(
            new Locales($default, array_values(array_unique([$default, 'en', 'ru']))),
            [$this->tempDir() . '/core', $this->tempDir() . '/theme'],
            $this->logger,
            $reportMisses,
        );
    }
}
