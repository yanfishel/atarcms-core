<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Theme\Check;

use AtarCms\Tests\Support\SkeletonSite;
use AtarCms\Theme\Check\Finding;
use AtarCms\Theme\Check\Report;
use AtarCms\Theme\Check\ThemeChecker;
use PHPUnit\Framework\TestCase;

/**
 * theme:check knows the site's forms (config/forms.php): a form with its own page needs form.php, and every form
 * needs its texts in the theme catalog (forms.{key}.title, intro, subject, sent, submit, a label per field and per option).
 */
final class ThemeCheckerFormsTest extends TestCase
{
    use SkeletonSite;

    private const array BOOKING = [
        'fields' => [
            'phone' => ['type' => 'tel', 'required' => true],
            'service' => ['type' => 'radio', 'options' => ['haircut', 'coloring']],
        ],
    ];

    private const string LABELS = "'forms' => ['booking' => ['title' => 'Book', 'intro' => 'Choose a day', 'subject' => 'Booking from {name}', 'sent' => 'Thanks', 'submit' => 'Book', 'phone' => 'Phone', 'service' => 'Service', 'service.haircut' => 'Haircut', 'service.coloring' => 'Coloring']]";

    protected function tearDown(): void
    {
        $this->restoreEnvironment();
        parent::tearDown();
    }

    public function testAFormWithItsOwnPageNeedsTheFormTemplate(): void
    {
        $report = $this->check(['booking' => self::BOOKING], withFormTemplate: false);

        self::assertSame(['ERROR [templates] themes/probe No form.php: the forms of config/forms.php (booking) answer 404 at /forms/{key}'], self::formFindings($report));
        self::assertSame([], self::formFindings($this->check(['booking' => self::BOOKING])));
    }

    public function testWithoutFormsNothingIsAsked(): void
    {
        self::assertSame([], self::formFindings($this->check([], withFormTemplate: false, labels: false)));
    }

    public function testAMissingLabelIsAWarning(): void
    {
        $report = $this->check(['booking' => self::BOOKING], labels: true, drop: 'service.coloring');

        self::assertSame(
            ['WARNING [catalogs] themes/probe/lang/en.php Missing texts of the form "booking": forms.booking.service.coloring'],
            self::formFindings($report),
        );
        self::assertSame(
            ['WARNING [catalogs] themes/probe/lang/en.php Missing texts of the form "booking": forms.booking.title, forms.booking.intro, forms.booking.subject, forms.booking.sent, forms.booking.submit, forms.booking.phone, forms.booking.service, forms.booking.service.haircut, forms.booking.service.coloring'],
            self::formFindings($this->check(['booking' => self::BOOKING], labels: false)),
        );
    }

    /**
     * @param array<string, mixed> $forms
     */
    private function check(array $forms, bool $withFormTemplate = true, bool $labels = true, ?string $drop = null): Report
    {
        $catalog = $labels ? self::LABELS : "'forms' => []";
        if ($drop !== null) {
            $catalog = (string) preg_replace("/, '" . preg_quote($drop, '/') . "' => '[^']*'/", '', $catalog);
        }
        $files = [
            'show.php' => "<h1>x</h1>\n",
            'index.php' => "<h1>x</h1>\n",
            'term.php' => "<h1>x</h1>\n",
            'lang/en.php' => "<?php\n\nreturn [" . $catalog . "];\n",
            'lang/ru.php' => "<?php\n\nreturn [" . $catalog . "];\n",
            'lang/he.php' => "<?php\n\nreturn [" . $catalog . "];\n",
        ];
        if ($withFormTemplate) {
            $files['form.php'] = "<h1><?= \$this->e(\$this->t('forms.' . \$form->key . '.title')) ?></h1>\n";
        }
        foreach ($files as $file => $contents) {
            $this->writeFile('themes/probe/' . $file, $contents);
        }

        return $this->bootSkeleton(['forms' => $forms])->container()->service(ThemeChecker::class)->check($this->tempDir() . '/themes/probe');
    }

    /**
     * @return list<string> the findings about forms
     */
    private static function formFindings(Report $report): array
    {
        return array_values(array_filter(
            array_map(static fn(Finding $f): string => $f->format(), $report->findings()),
            static fn(string $line): bool => str_contains($line, 'form.php') || str_contains($line, 'the form "'),
        ));
    }
}
