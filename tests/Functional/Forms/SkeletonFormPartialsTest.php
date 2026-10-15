<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Forms;

use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Functional\Skeleton\SkeletonTestCase;
use AtarCms\View\Renderer;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * The skeleton's form partials with a form beside the booking one: radio options get ids that no error paragraph can
 * take, and the contact form is never a dialog (its endpoint answers with a page, not JSON).
 */
final class SkeletonFormPartialsTest extends SkeletonTestCase
{
    protected function application(): Application
    {
        // The skeleton's environment and storage, then the site booted again with a survey form.
        parent::application();

        return Application::boot(new Paths(static::SITE, $this->tempDir() . '/storage'), ['forms.survey' => ['fields' => [
            'email' => ['type' => 'email', 'required' => true],
            'size' => ['type' => 'radio', 'options' => ['error', 'small']],
        ]]]);
    }

    public function testARadioOptionNeverTakesTheErrorParagraphsId(): void
    {
        $body = (string) $this->browser->get('/forms/survey')->getBody();

        self::assertStringContainsString('<input type="radio" id="survey-size-option-error" name="size" value="error"', $body);
        self::assertStringContainsString('<input type="radio" id="survey-size-option-small" name="size" value="small"', $body);
        self::assertStringContainsString('id="survey-size-error" data-error-for="size"', $body);
        preg_match_all('/\sid="([^"]+)"/', $body, $ids);
        self::assertSame([], array_keys(array_filter(array_count_values($ids[1]), static fn(int $count): bool => $count > 1)));
    }

    public function testTheContactFormIsNoDialog(): void
    {
        $renderer = $this->app->container()->service(Renderer::class);
        $request = new Psr17Factory()->createServerRequest('GET', 'https://localhost/')->withAttribute(RequestLocale::class, new RequestLocale('en', false));

        self::assertSame('', trim($renderer->render('partials/form-dialog', ['key' => 'contact'], $request)));
        self::assertStringContainsString('<dialog class="form-dialog" id="form-survey"', $renderer->render('partials/form-dialog', ['key' => 'survey'], $request));
    }
}
