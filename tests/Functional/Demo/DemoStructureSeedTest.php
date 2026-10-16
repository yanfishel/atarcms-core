<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Demo;

use AtarCms\Cli\Output;
use AtarCms\Content\Taxonomy;
use AtarCms\Demo\DemoSeeder;
use AtarCms\Demo\DemoTexts;
use AtarCms\Demo\DemoWiper;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Functional\FunctionalTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\TempDirectory;

/**
 * demo:seed on the fixture site builds the structure config/demo.php (the config key "demo") names: its sections,
 * pages and tags instead of the generic ones.
 */
final class DemoStructureSeedTest extends FunctionalTestCase
{
    use TempDirectory;

    private const array DEMO = [
        'sections' => [
            'services-x' => [
                'names' => ['en' => 'Services', 'ru' => 'Услуги'],
                'children' => [
                    'repair' => ['en' => 'Repair', 'ru' => 'Ремонт'],
                    'design' => ['en' => 'Design', 'ru' => 'Дизайн'],
                ],
            ],
        ],
        'pages' => ['about-me' => ['en' => 'About me', 'ru' => 'Обо мне']],
        'tags' => ['ru' => ['Кухни', 'Ванные']],
    ];

    /** @var array<string, mixed> the config overrides of the next boot */
    private array $overrides = [];

    public function testTheSeedBuildsTheConfiguredStructure(): void
    {
        $this->seed(['demo' => self::DEMO]);

        self::assertSame([
            ['slug' => 'services-x', 'name' => 'Services', 'parent' => null, 'position' => 0],
            ['slug' => 'repair', 'name' => 'Repair', 'parent' => 'services-x', 'position' => 0],
            ['slug' => 'design', 'name' => 'Design', 'parent' => 'services-x', 'position' => 1],
        ], $this->sections('en'));
        self::assertSame(['Услуги', 'Ремонт', 'Дизайн'], array_column($this->sections('ru'), 'name'));
        self::assertSame(['About me', 'Обо мне'], $this->titles('about-me'));
        self::assertSame(0, Cast::int($this->db->scalar("SELECT COUNT(*) FROM entries WHERE collection = 'info' AND slug = 'about'")), 'no generic page');
        $tags = array_map(static fn(array $row): string => Cast::string($row['name']), $this->db->select("SELECT name FROM terms WHERE taxonomy = 'tags' AND locale = 'ru' ORDER BY name"));
        self::assertSame(['Ванные', 'Кухни'], $tags, 'the news carry only the given tags');
    }

    public function testAFlatRootTaxonomyIgnoresTheChildren(): void
    {
        $this->seed(['demo' => self::DEMO, 'taxonomies' => [
            Taxonomy::make('topics')->label('Topics')->hierarchical()->route('/topics/{slug}', 2),
            Taxonomy::make('tags')->label('Tags')->route('/tags/{slug}'),
            Taxonomy::make('regions')->label('Regions'),
            Taxonomy::make('sections')->label('Sections')->route('/{slug}', 2),
        ]]);

        self::assertSame([['slug' => 'services-x', 'name' => 'Services', 'parent' => null, 'position' => 0]], $this->sections('en'));
        self::assertSame(DemoSeeder::ARTICLES_PER_SECTION, Cast::int($this->db->scalar("SELECT COUNT(*) FROM entries WHERE collection = 'stories' AND locale = 'en'")), 'the top section is the leaf');
    }

    public function testALanguageWithoutANameGetsALoremOne(): void
    {
        $this->seed(['demo' => ['sections' => ['services-x' => ['names' => ['en' => 'Services']]]]]);

        self::assertSame('Services', $this->sections('en')[0]['name']);
        self::assertMatchesRegularExpression('/^[А-Я][а-яё ]+$/u', $this->sections('ru')[0]['name']);
    }

    public function testWithoutTheFileTheGenericStructureIsSeeded(): void
    {
        $this->seed([]);

        $tops = array_values(array_filter($this->sections('en'), static fn(array $section): bool => $section['parent'] === null));
        self::assertSame(array_keys(DemoTexts::SECTIONS), array_column($tops, 'slug'));
        self::assertSame(['About us', 'О нас'], $this->titles('about'));
    }

    /**
     * The cold run of 2026-10-16: a demo page at an address the site already uses. Here the preset page "legal" takes
     * it (the presets are made before the pages).
     */
    public function testATakenAddressIsSkippedAndReported(): void
    {
        $made = $this->seed(['demo' => ['pages' => ['legal' => ['en' => 'Legal demo'], 'about-me' => ['en' => 'About me']]]]);

        self::assertSame(1, Cast::int($this->db->scalar("SELECT COUNT(*) FROM entries WHERE collection = 'info' AND slug = 'legal' AND locale = 'en'")));
        self::assertSame('legal', $this->db->scalar("SELECT preset FROM entries WHERE collection = 'info' AND slug = 'legal' AND locale = 'en'"), 'the preset page keeps its address');
        self::assertSame(0, Cast::int($this->db->scalar("SELECT COUNT(*) FROM entries WHERE title = 'Legal demo'")));
        self::assertSame(['About me'], array_slice($this->titles('about-me'), 0, 1), 'the seed goes on');
        self::assertCount(1, $made['skipped'], implode("\n", $made['skipped']));
        self::assertMatchesRegularExpression('~^skipped: page "legal" \(validation\.[a-z_.]+\)$~', $made['skipped'][0]);
    }

    public function testTheCommandPrintsTheSkips(): void
    {
        $this->overrides = ['demo' => ['pages' => ['legal' => ['en' => 'Legal demo']]]];
        $this->bootApp();
        $stdout = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $stderr = fopen('php://memory', 'w+') ?: self::fail('No stream');

        $code = $this->app->console()->run(['demo:seed', '--force'], new Output($stdout, $stderr));

        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        self::assertSame(0, $code, $out);
        self::assertMatchesRegularExpression('~^skipped: page "legal" \(validation\.[a-z_.]+\)$~m', $out);
    }

    public function testSeedingTheSameSiteTwiceGivesTheSameContent(): void
    {
        $demo = ['demo' => [...self::DEMO, 'pages' => ['legal' => ['en' => 'Legal demo'], 'about-me' => ['en' => 'About me']]]];
        $first = $this->seed($demo);
        $count = Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries'));
        $slugs = $this->db->select('SELECT slug FROM entries ORDER BY id');
        $sections = $this->sections('ru');
        $this->app->container()->service(DemoWiper::class)->wipe();

        $second = $this->seed($demo);

        self::assertSame($count, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
        self::assertSame($slugs, $this->db->select('SELECT slug FROM entries ORDER BY id'));
        self::assertSame($sections, $this->sections('ru'));
        self::assertSame($first['counts'], $second['counts']);
        self::assertSame($first['skipped'], $second['skipped']);
    }

    public function testTheCommandRefusesAMalformedFileAndDeletesNothing(): void
    {
        $this->seed([]);
        $entries = Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries'));
        $terms = Cast::int($this->db->scalar('SELECT COUNT(*) FROM terms'));
        self::assertGreaterThan(0, $entries);
        $this->overrides = ['demo' => ['sections' => ['Услуги' => ['names' => ['ru' => 'Услуги']]]]];
        $this->bootApp();
        $stdout = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $stderr = fopen('php://memory', 'w+') ?: self::fail('No stream');

        $code = $this->app->console()->run(['demo:seed', '--force'], new Output($stdout, $stderr));

        rewind($stdout);
        rewind($stderr);
        $out = (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr);
        self::assertSame(1, $code, $out);
        self::assertStringContainsString('nothing was deleted', $out);
        self::assertStringContainsString('- config/demo.php: the section "Услуги"', $out);
        self::assertSame($entries, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
        self::assertSame($terms, Cast::int($this->db->scalar('SELECT COUNT(*) FROM terms')));
    }

    public function testAMalformedFileStopsTheSeedBeforeAnythingIsDeleted(): void
    {
        $this->overrides = ['demo' => ['sections' => ['Услуги' => ['names' => ['ru' => 'Услуги']]]]];
        $this->bootApp();

        $problems = $this->app->container()->service(DemoSeeder::class)->problems(false);

        self::assertCount(1, $problems, implode("\n", $problems));
        self::assertStringContainsString('config/demo.php: the section "Услуги"', $problems[0]);
    }

    protected function application(): Application
    {
        $storage = $this->tempDir() . '/storage';
        foreach (['cache', 'logs', 'tmp', 'uploads'] as $directory) {
            if (!is_dir($storage . '/' . $directory)) {
                mkdir($storage . '/' . $directory, 0777, true);
            }
        }

        return Application::boot(new Paths(static::SITE, $storage), $this->overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{counts: array<string, array<string, int>>, files: int, skipped: list<string>} what seed() returned
     */
    private function seed(array $overrides): array
    {
        $this->overrides = $overrides;
        $this->bootApp();

        return $this->app->container()->service(DemoSeeder::class)->seed(null);
    }

    /**
     * The sections of the root taxonomy in $locale, in tree order.
     *
     * @return list<array{slug: string, name: string, parent: string|null, position: int}>
     */
    private function sections(string $locale): array
    {
        $rows = $this->db->select(
            "SELECT t.slug, t.name, p.slug AS parent, t.position FROM terms t LEFT JOIN terms p ON p.id = t.parent_id
             WHERE t.taxonomy = 'sections' AND t.locale = ? ORDER BY COALESCE(p.position, t.position), t.parent_id IS NOT NULL, t.position",
            [$locale],
        );

        return array_map(static fn(array $row): array => [
            'slug' => Cast::string($row['slug']),
            'name' => Cast::string($row['name']),
            'parent' => $row['parent'] === null ? null : Cast::string($row['parent']),
            'position' => Cast::int($row['position']),
        ], $rows);
    }

    /**
     * @return list<string> the titles of the root collection's page $slug, the default language first
     */
    private function titles(string $slug): array
    {
        return array_map(
            static fn(array $row): string => Cast::string($row['title']),
            $this->db->select("SELECT title FROM entries WHERE collection = 'info' AND slug = ? ORDER BY locale = 'en' DESC, locale", [$slug]),
        );
    }
}
