<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\View;

use AtarCms\Tests\Support\TempDirectory;
use AtarCms\View\Renderer;
use AtarCms\View\ThemeResolver;
use BadMethodCallException;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class RendererTest extends TestCase
{
    use TempDirectory;

    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new Renderer(new ThemeResolver([$this->tempDir()]));
    }

    public function testRendersDataAndEscapes(): void
    {
        $this->writeFile('hello.php', '<p><?= $this->e($name) ?></p>');

        self::assertSame(
            '<p>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; Анна</p>',
            $this->renderer->render('hello', ['name' => '<script>alert("x")</script> & Анна']),
        );
    }

    public function testEscapeHandlesNullAndRejectsArrays(): void
    {
        $this->writeFile('null.php', '[<?= $this->e(null) ?>]');
        $this->writeFile('array.php', '<?= $this->e([1]) ?>');

        self::assertSame('[]', $this->renderer->render('null'));
        $this->expectException(InvalidArgumentException::class);
        $this->renderer->render('array');
    }

    public function testWrapsTemplateInLayout(): void
    {
        $this->writeFile('layouts/default.php', '<html><title><?= $this->e($title) ?></title><?= $content ?></html>');
        $this->writeFile('page.php', "<?php \$this->layout('layouts/default', ['title' => 'T & C']); ?><main><?= \$this->e(\$body) ?></main>");

        self::assertSame(
            '<html><title>T &amp; C</title><main>Body</main></html>',
            $this->renderer->render('page', ['body' => 'Body']),
        );
    }

    public function testRendersPartials(): void
    {
        $this->writeFile('partials/item.php', '<li><?= $this->e($label) ?></li>');
        $this->writeFile('list.php', "<ul><?= \$this->partial('partials/item', ['label' => 'one']) ?></ul>");

        self::assertSame('<ul><li>one</li></ul>', $this->renderer->render('list'));
    }

    public function testFallsBackThroughCandidates(): void
    {
        $this->writeFile('show.php', 'generic');

        self::assertSame('generic', $this->renderer->render(['collections/news/show', 'show']));
    }

    public function testCallsRegisteredHelpers(): void
    {
        $this->renderer->helper('upper', static fn(string $value): string => strtoupper($value));
        $this->writeFile('helper.php', "<?= \$this->e(\$this->upper('abc')) ?>");

        self::assertSame('ABC', $this->renderer->render('helper'));
    }

    public function testUnknownHelperThrows(): void
    {
        $this->writeFile('unknown.php', '<?= $this->nope() ?>');

        $this->expectException(BadMethodCallException::class);

        $this->renderer->render('unknown');
    }

    public function testHelperCannotShadowBuiltIns(): void
    {
        $this->expectException(LogicException::class);

        $this->renderer->helper('e', static fn(): string => '');
    }

    #[DataProvider('invalidVariableNameProvider')]
    public function testRejectsInvalidVariableNames(string $key): void
    {
        $this->writeFile('vars.php', 'x');

        $this->expectException(InvalidArgumentException::class);

        $this->renderer->render('vars', [$key => 'value']);
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidVariableNameProvider(): array
    {
        return [
            ['this'],
            ['__file'],
            ['1abc'],
            ['with-dash'],
        ];
    }

    public function testExceptionInsideTemplateClosesOutputBuffers(): void
    {
        $this->writeFile('layouts/default.php', '<html><?= $content ?></html>');
        $this->writeFile('broken.php', "<?php \$this->layout('layouts/default'); ?>partial output<?php throw new RuntimeException('boom'); ?>");
        $level = ob_get_level();

        try {
            $this->renderer->render('broken');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame($level, ob_get_level());
    }

    public function testRequestHelpersSeeTheRequestInTemplatesLayoutsAndPartials(): void
    {
        $this->renderer->requestHelper('path', static fn(ServerRequestInterface $request): string => $request->getUri()->getPath());
        $this->writeFile('layouts/main.php', '<layout><?= $this->e($this->path()) ?></layout><?= $content ?>');
        $this->writeFile('partials/p.php', '<partial><?= $this->e($this->path()) ?></partial>');
        $this->writeFile('page.php', "<?php \$this->layout('layouts/main'); ?><page><?= \$this->e(\$this->path()) ?></page><?= \$this->partial('partials/p') ?>");

        $html = $this->renderer->render('page', [], (new Psr17Factory())->createServerRequest('GET', '/x'));

        self::assertSame('<layout>/x</layout><page>/x</page><partial>/x</partial>', $html);
    }

    public function testRequestHelperWithoutRequestFails(): void
    {
        $this->renderer->requestHelper('path', static fn(ServerRequestInterface $request): string => '');
        $this->writeFile('page.php', '<?= $this->path() ?>');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs the current request');

        $this->renderer->render('page');
    }

    public function testHelperNamesMustBeUnique(): void
    {
        $this->renderer->helper('thing', static fn(): string => 'a');

        $this->expectException(LogicException::class);

        $this->renderer->requestHelper('thing', static fn(ServerRequestInterface $request): string => 'b');
    }
}
