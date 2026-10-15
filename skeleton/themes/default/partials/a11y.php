<?php
/**
 * The accessibility toolbar (Site settings → General switches it and picks its corner): a round button in that corner
 * opens a panel of display options towards the page. js/a11y.js turns the options into a11y-* classes on <html> and remembers
 * them in localStorage ("atar-a11y"; the layout's bootstrap applies them before the first paint). It needs JavaScript:
 * without .js nothing shows (the footer still links the statement). The panel is not modal: Tab moves on past it, and
 * Escape, the close button or a click elsewhere close it and give the focus back to the button. Each option is a
 * toggle button (aria-pressed); the text size is two buttons and the step they set, announced politely.
 *
 * @var AtarCms\View\Template $this
 * @var string|null $statement the address of the accessibility statement (the preset page "accessibility") in this
 *      language, or null while it is not published there
 * @var string $position the button's corner (Settings::TOOLBAR_POSITIONS: top|bottom and start|end, so right-to-left
 *      pages mirror it), placed by site.css
 */
// Group => its options (data-a11y-option; the class is "a11y-" and the option, the label site.a11y.options.*).
$groups = [
    'text' => ['spacing', 'font'],
    'colour' => ['contrast', 'grayscale', 'invert'],
    'navigation' => ['links', 'focus', 'cursor', 'still'],
    'reading' => ['guide', 'no-images'],
];
$open = $this->t('site.a11y.open');
$close = $this->t('site.a11y.close');
?>
<div class="a11y" data-a11y data-a11y-position="<?= $this->e($position) ?>">
    <section class="a11y-panel" id="a11y-panel" role="dialog" aria-labelledby="a11y-title" tabindex="-1" hidden data-a11y-panel>
        <header class="a11y-header">
            <h2 id="a11y-title"><?= $this->partial('partials/icon', ['name' => 'accessibility']) ?><?= $this->e($this->t('site.a11y.title')) ?></h2>
            <button type="button" class="a11y-close" aria-label="<?= $this->e($close) ?>" title="<?= $this->e($close) ?>" data-a11y-close><?= $this->partial('partials/icon', ['name' => 'x']) ?></button>
        </header>
        <div class="a11y-body">
            <?php foreach ($groups as $group => $options): ?>
            <fieldset class="a11y-group">
                <legend><?= $this->e($this->t('site.a11y.groups.' . $group)) ?></legend>
                <?php if ($group === 'text'): ?>
                <div class="a11y-size">
                    <button type="button" class="a11y-step" aria-label="<?= $this->e($this->t('site.a11y.smaller')) ?>" title="<?= $this->e($this->t('site.a11y.smaller')) ?>" data-a11y-size="-1"><?= $this->partial('partials/icon', ['name' => 'minus']) ?></button>
                    <p class="a11y-size-value"><span id="a11y-size-label"><?= $this->e($this->t('site.a11y.size')) ?></span> <output aria-labelledby="a11y-size-label" data-a11y-size-value>100%</output></p>
                    <button type="button" class="a11y-step" aria-label="<?= $this->e($this->t('site.a11y.larger')) ?>" title="<?= $this->e($this->t('site.a11y.larger')) ?>" data-a11y-size="1"><?= $this->partial('partials/icon', ['name' => 'plus']) ?></button>
                </div>
                <?php endif ?>
                <ul class="a11y-options">
                    <?php foreach ($options as $option): ?>
                    <li><button type="button" class="a11y-option" aria-pressed="false" data-a11y-option="<?= $this->e($option) ?>"><?= $this->e($this->t('site.a11y.options.' . str_replace('-', '_', $option))) ?></button></li>
                    <?php endforeach ?>
                </ul>
            </fieldset>
            <?php endforeach ?>
        </div>
        <footer class="a11y-footer">
            <button type="button" class="a11y-reset" data-a11y-reset><?= $this->partial('partials/icon', ['name' => 'rotate-ccw']) ?><?= $this->e($this->t('site.a11y.reset')) ?></button>
            <?php if ($statement !== null): ?>
            <a class="a11y-statement" href="<?= $this->e($statement) ?>"><?= $this->e($this->t('site.a11y.statement')) ?></a>
            <?php endif ?>
        </footer>
    </section>
    <button type="button" class="a11y-trigger" aria-expanded="false" aria-controls="a11y-panel" aria-label="<?= $this->e($open) ?>" title="<?= $this->e($open) ?>" data-a11y-trigger><?= $this->partial('partials/icon', ['name' => 'accessibility']) ?></button>
    <div class="a11y-guide-band" aria-hidden="true"></div>
</div>
