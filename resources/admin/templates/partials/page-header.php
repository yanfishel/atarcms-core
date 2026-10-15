<?php
/**
 * The heading of a page: its <h1> (with the content language's pill on a per-language screen) and, on the right, the
 * page's actions as links (the primary one is filled). A screen that needs more in its header (the entry and term
 * forms) renders its own.
 *
 * @var AtarCms\View\Template $this
 * @var string $title
 * @var list<array{label: string, url: string, icon?: string, primary?: bool, dialog?: bool}> $actions (optional); "dialog" opens the link in the screen's dialog (data-dialog-open, dialog.js)
 * @var string|null $locale (optional) the content language the screen shows (partials/locale-badge.php)
 * @var string $aside (optional) rendered markup on the right of the title, such as the Translations line of a form
 */
$pill = isset($locale) ? trim($this->partial('partials/locale-badge', ['locale' => $locale])) : '';
?>
<div class="admin-page-header">
    <h1><?= $this->e($title) ?><?= $pill === '' ? '' : ' ' . $pill ?></h1>
    <?= $aside ?? '' ?>
    <?php if (($actions ?? []) !== []): ?>
    <div class="admin-page-actions">
        <?php foreach ($actions as $action): ?>
        <a class="admin-button <?= ($action['primary'] ?? false) ? 'admin-button-primary' : 'admin-button-secondary' ?>" href="<?= $this->e($action['url']) ?>"<?= ($action['dialog'] ?? false) ? ' data-dialog-open' : '' ?>><?= isset($action['icon']) ? $this->icon($action['icon']) : '' ?><?= $this->e($action['label']) ?></a>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</div>
