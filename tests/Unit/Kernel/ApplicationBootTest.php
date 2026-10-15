<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel;

use AtarCms\Admin\LanguageMode;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class ApplicationBootTest extends TestCase
{
    use TempDirectory;

    public function testRejectsModulesThatAreNotModuleSubclasses(): void
    {
        $this->writeFile('site/config/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return ['theme' => 'test', 'url' => 'https://localhost', 'modules' => [stdClass::class]];
            PHP);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('stdClass');

        Application::fromSite($this->tempDir() . '/site');
    }

    /**
     * The web and bin/atar boot the same way, so a missing or invalid app.url stops both, before any request.
     */
    public function testRejectsASiteWithoutAValidUrl(): void
    {
        $this->writeFile('site/config/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return ['theme' => 'test', 'url' => 'example.com'];
            PHP);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('app.url must be the absolute http(s) URL of the site root');

        Application::fromSite($this->tempDir() . '/site');
    }

    /**
     * theme:check boots a second application for another theme without touching the site's config.
     */
    public function testConfigOverridesReplaceSiteValues(): void
    {
        $this->writeFile('site/config/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return ['theme' => 'test', 'url' => 'https://localhost'];
            PHP);

        $app = Application::boot(new Paths($this->tempDir() . '/site'), ['app.theme' => 'other']);

        self::assertSame('other', $app->container()->service(Config::class)->string('app.theme'));
    }

    public function testRejectsAnUnknownTimeZone(): void
    {
        $this->writeFile('site/config/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return ['theme' => 'test', 'url' => 'https://localhost', 'timezone' => 'Europe/Atlantis'];
            PHP);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('app.timezone must be a time zone identifier');

        Application::fromSite($this->tempDir() . '/site');
    }

    public function testTheAdminLanguagesAreLinkedByDefaultAndAnUnknownModeStopsTheBoot(): void
    {
        $this->writeFile('site/config/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return ['theme' => 'test', 'url' => 'https://localhost'];
            PHP);
        self::assertSame(LanguageMode::Linked, Application::fromSite($this->tempDir() . '/site')->container()->service(LanguageMode::class));
        self::assertSame(LanguageMode::Separate, Application::boot(new Paths($this->tempDir() . '/site'), ['locales.admin' => 'separate'])->container()->service(LanguageMode::class));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('locales.admin must be "linked" or "separate"; got "both"');

        Application::boot(new Paths($this->tempDir() . '/site'), ['locales.admin' => 'both']);
    }
}
