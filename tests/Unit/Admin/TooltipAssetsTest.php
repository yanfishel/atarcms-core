<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The tooltips (Plan 27): one script, tooltip.js, turns every title into the page's own tip through the Popover API
 * and CSS anchor positioning; the admin and the skeleton theme load the same file, and each stylesheet styles #tooltip.
 */
final class TooltipAssetsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private const string ASSETS = self::ROOT . '/resources/admin/assets';

    private const string THEME = self::ROOT . '/skeleton/themes/default';

    public function testTheTooltipModuleNeverInjectsMarkupStylesOrUrls(): void
    {
        $tooltip = self::script();

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute', '.style', 'https://', 'http://', 'import ', 'fetch(', 'admin-', 'svg title'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $tooltip, $forbidden);
        }
    }

    /**
     * Without the Popover API or anchor positioning the script does nothing: the browser shows the title as usual.
     */
    public function testItDoesNothingWithoutPopoversAndAnchorPositioning(): void
    {
        $tooltip = self::script();

        self::assertStringContainsString("typeof HTMLElement.prototype.showPopover === 'function'", $tooltip);
        self::assertStringContainsString("CSS.supports('anchor-name: --a')", $tooltip);
        self::assertStringContainsString("CSS.supports('position-area: block-start')", $tooltip, 'Chrome 125–128 had anchor-name without position-area');
    }

    /**
     * One shared element, a manual popover with the tooltip role, holds the text through textContent only.
     */
    public function testOneSharedTextOnlyPopover(): void
    {
        $tooltip = self::script();

        foreach ([
            "document.createElement('div')",
            "tip.id = 'tooltip';",
            "tip.popover = 'manual';",
            "tip.role = 'tooltip';",
            'tip.textContent = ',
            'tip.showPopover()',
            'tip.hidePopover()',
        ] as $code) {
            self::assertStringContainsString($code, $tooltip, $code);
        }
    }

    /**
     * Hover (mouse and pen, after a delay, at once from one tip to the next) and keyboard focus show it; CKEditor's
     * UI, options, frames and [data-no-tooltip] keep the native title.
     */
    public function testTriggersAndExceptions(): void
    {
        $tooltip = self::script();

        foreach ([
            "'pointerover'",
            "'pointerout'",
            "event.pointerType === 'touch'",
            "'focusin'",
            "'focusout'",
            "'toggle'",
            "'keydown'",
            "matches(':focus-visible')",
            "closest('[title]')",
            "'.ck, option, iframe, [data-no-tooltip]'",
            'SHOW_MS',
            'CHAIN_MS',
        ] as $code) {
            self::assertStringContainsString($code, $tooltip, $code);
        }
    }

    /**
     * While the tip shows, the title waits in data-tooltip-title (no second, native box) and comes back on hide, also
     * before an htmx swap and when the page is left; a trigger named only by its title keeps that name as aria-label.
     */
    public function testTheTitleAndTheAccessibleNameAlwaysComeBack(): void
    {
        $tooltip = self::script();

        foreach ([
            'dataset.tooltipTitle',
            'parked',
            "'htmx:beforeSwap'",
            "'pagehide'",
            '.ariaLabel = ',
            'ariaDescribedByElements',
            "'Escape'",
            "'scroll'",
            "'pointerdown'",
            "'resize'",
            'event.preventDefault();',
        ] as $code) {
            self::assertStringContainsString($code, $tooltip, $code);
        }
    }

    /**
     * Anchoring is CSS only: the trigger carries data-tooltip-anchor (dataset, no class: the file is shared with the
     * themes) and the stylesheets place #tooltip above it, flipping near an edge.
     */
    public function testAnchoringIsCssOnlyInBothStylesheets(): void
    {
        self::assertStringContainsString('dataset.tooltipAnchor', self::script());

        foreach ([self::ASSETS . '/admin.css', self::THEME . '/assets/css/site.css'] as $file) {
            $css = (string) file_get_contents($file);
            self::assertStringContainsString('[data-tooltip-anchor] { anchor-name: --tooltip; }', $css, $file);
            self::assertMatchesRegularExpression('/#tooltip \{[^}]*position-anchor: --tooltip; position-area: block-start; position-try-fallbacks: flip-block, flip-inline;[^}]*pointer-events: none;/', $css, $file);
            self::assertStringContainsString('#tooltip:popover-open { opacity: 1; transition: opacity .12s; }', $css, $file . ': it fades in, and hides at once (a fade-out would lose its anchor)');
            self::assertDoesNotMatchRegularExpression('/#tooltip {[^}]*transition/', $css, $file);
            self::assertStringContainsString('@starting-style {', $css, $file);
        }
    }

    /**
     * The old focus tip of locked buttons (a ::after of their title) is gone: the shared tooltip shows their reason.
     */
    public function testTheOldLockedButtonTipIsGone(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/admin.css');

        self::assertStringNotContainsString('attr(title)', $css);
        self::assertStringNotContainsString('.admin-locked:focus-visible::after', $css);
    }

    /**
     * Every admin page loads it, the login and error pages too, as a nonce'd module.
     */
    public function testTheAdminLayoutLoadsItOnEveryPage(): void
    {
        $layout = (string) file_get_contents(self::ROOT . '/resources/admin/templates/layouts/admin.php');
        $tag = '<script type="module" src="<?= $this->e($this->asset(\'tooltip.js\')) ?>" nonce="<?= $this->e($this->nonce()) ?>"></script>';

        self::assertSame(1, substr_count($layout, $tag));
        self::assertLessThan(strpos($layout, '<?php if ($user !== null): ?>'), strpos($layout, $tag), 'guests too');
    }

    /**
     * Icon-only controls carry a title like the top bar's triggers, so they get a tip: the burger and the pagination's
     * previous and next links.
     */
    public function testIconOnlyControlsCarryATitle(): void
    {
        $layout = (string) file_get_contents(self::ROOT . '/resources/admin/templates/layouts/admin.php');
        $pagination = (string) file_get_contents(self::ROOT . '/resources/admin/templates/partials/pagination.php');

        self::assertMatchesRegularExpression('~<button type="button" class="admin-burger[^"]*" data-drawer-open aria-controls="sidebar" aria-expanded="false" title="<\?= \$this->e\(\$this->t\(\'admin\.layout\.open_menu\'\)\) \?>">~', $layout);
        foreach (['previous' => 'prev', 'next' => 'next'] as $key => $rel) {
            self::assertStringContainsString(sprintf('\' rel="%s" title="\' . $this->e($this->t(\'admin.pagination.%s\')) . \'"\'', $rel, $key), $pagination, $key);
        }
    }

    /**
     * The skeleton theme ships the same file, byte for byte, and its layout loads it.
     */
    public function testTheSkeletonThemeShipsTheSameScript(): void
    {
        self::assertFileEquals(self::ASSETS . '/tooltip.js', self::THEME . '/assets/js/tooltip.js', 'copy resources/admin/assets/tooltip.js into the skeleton theme');
        self::assertStringContainsString(
            '<script type="module" src="<?= $this->e($this->asset(\'js/tooltip.js\')) ?>"></script>',
            (string) file_get_contents(self::THEME . '/layouts/default.php'),
        );
    }

    private static function script(): string
    {
        return (string) file_get_contents(self::ASSETS . '/tooltip.js');
    }
}
