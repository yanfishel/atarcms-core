<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Skeleton;

use AtarCms\Cli\CommandList;
use AtarCms\Kernel\ModuleList;
use AtarCms\Tests\Support\Regex;
use AtarCms\Tests\Support\SkeletonSite;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The site builder skill shipped with the skeleton (open Agent Skills format): its frontmatter, the pointer Claude
 * Code reads, the files it names, the copy of the theme guide, and the bin/atar commands it tells agents to run.
 */
final class SiteBuilderSkillTest extends TestCase
{
    use SkeletonSite;

    private const string NAME = 'building-atarcms-sites';

    private const string SKILL = self::SKELETON . '/.agents/skills/' . self::NAME;

    private const string POINTER = self::SKELETON . '/.claude/skills/' . self::NAME . '/SKILL.md';

    protected function tearDown(): void
    {
        $this->restoreEnvironment();
        parent::tearDown();
    }

    public function testTheFrontmatterNamesTheSkillAndWhenToUseIt(): void
    {
        [$frontmatter] = self::split((string) file_get_contents(self::SKILL . '/SKILL.md'));

        self::assertSame(self::NAME, $frontmatter['name'] ?? null);
        self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', self::NAME);
        $description = $frontmatter['description'] ?? '';
        self::assertStringStartsWith('Use when', $description);
        self::assertLessThanOrEqual(500, strlen($description));
        self::assertSame(['name', 'description'], array_keys($frontmatter));
    }

    public function testClaudeCodeFindsThePointerToTheSameSkill(): void
    {
        [$skill] = self::split((string) file_get_contents(self::SKILL . '/SKILL.md'));
        [$pointer, $body] = self::split((string) file_get_contents(self::POINTER));

        self::assertSame($skill, $pointer);
        self::assertStringContainsString('.agents/skills/' . self::NAME . '/SKILL.md', $body);
        self::assertSame(['SKILL.md'], array_values(array_diff(scandir(dirname(self::POINTER)) ?: [], ['.', '..'])));
        self::assertStringContainsString('.agents/skills/' . self::NAME . '/', (string) file_get_contents(self::SKELETON . '/AGENTS.md'));
        self::assertSame("@AGENTS.md\n", file_get_contents(self::SKELETON . '/CLAUDE.md'));
    }

    public function testEveryFileOfTheSkillIsNamedInItAndEveryNamedFileExists(): void
    {
        $skill = (string) file_get_contents(self::SKILL . '/SKILL.md');
        preg_match_all('~\b((?:references|assets)/[A-Za-z0-9._/-]+\.[a-z]+)~', $skill, $named);
        $named = array_values(array_unique($named[1]));
        sort($named);

        self::assertSame(self::files(), $named);
    }

    public function testTheThemeGuideIsACopyOfTheCoreOne(): void
    {
        self::assertFileEquals(self::SKELETON . '/../docs/theme-guide.md', self::SKILL . '/references/theme-guide.md', 'copy docs/theme-guide.md into the skill');
    }

    public function testEveryCommandItNamesExistsWithItsOptions(): void
    {
        $commands = new CommandList($this->bootSkeleton()->container()->service(ModuleList::class)->commands());
        $texts = [self::SKELETON . '/AGENTS.md' => (string) file_get_contents(self::SKELETON . '/AGENTS.md')];
        foreach ([...self::files(), 'SKILL.md'] as $file) {
            $texts[$file] = (string) file_get_contents(self::SKILL . '/' . $file);
        }
        $checked = 0;
        foreach ($texts as $file => $text) {
            if (str_ends_with($file, 'theme-guide.md')) {
                continue;
            }
            preg_match_all('~bin/atar ([a-z][a-z:-]*)((?: --?[a-z-]+(?:=\S*)?| <[^>]+>| [a-z0-9@._/-]+)*)~', $text, $calls, PREG_SET_ORDER);
            foreach ($calls as [, $name, $rest]) {
                if (isset(CommandList::BUILT_IN[$name])) {
                    continue; // built into the console (list, help)
                }
                self::assertTrue($commands->has($name), sprintf('%s names "bin/atar %s"', $file, $name));
                preg_match_all('~--([a-z-]+)~', $rest, $options);
                foreach ($options[1] as $option) {
                    self::assertStringContainsString('--' . $option, $commands->description($name), sprintf('%s: bin/atar %s --%s', $file, $name, $option));
                }
                ++$checked;
            }
        }
        self::assertGreaterThan(10, $checked);
    }

    public function testTheBriefTemplateHasEverySection(): void
    {
        $template = (string) file_get_contents(self::SKILL . '/assets/site-brief.template.md');
        preg_match_all('/^## (.+)$/m', $template, $headings);

        self::assertSame(['Project', 'Languages', 'Structure', 'News', 'Other collections', 'Contacts', 'Social links', 'Menus', 'Design source', 'Design notes', 'Design analysis', 'Launch', 'Decisions', 'Status'], $headings[1]);
    }

    public function testTheFilesArePlainMarkdown(): void
    {
        foreach ([...self::files(), 'SKILL.md'] as $file) {
            $text = (string) file_get_contents(self::SKILL . '/' . $file);
            self::assertStringNotContainsString("\r", $text, $file);
            self::assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text, $file . ': no emoji');
        }
    }

    /**
     * @return list<string> the skill's files besides SKILL.md, relative, sorted
     */
    private static function files(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SKILL, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getFilename() !== 'SKILL.md') {
                $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen(self::SKILL) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array{0: array<string, string>, 1: string} the frontmatter (one "key: value" per line) and the body
     */
    private static function split(string $markdown): array
    {
        self::assertMatchesRegularExpression('/\A---\n(.*?)\n---\n/s', $markdown);
        $parts = Regex::match('/\A---\n(.*?)\n---\n(.*)\z/s', $markdown);
        $frontmatter = [];
        foreach (explode("\n", $parts[1]) as $line) {
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $frontmatter[$key] = $value;
        }

        return [$frontmatter, $parts[2]];
    }
}
