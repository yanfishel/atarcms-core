<?php
/**
 * The menu screen: one location's items of one language in the tree editor. The header ends with "Add item" for this
 * location and language (as on the Sections screen); it and each row's Add inside, Edit and Delete open the item pages, which dialog.js loads
 * into the screen's dialog (data-dialog-open); without JavaScript they are full pages.
 *
 * @var AtarCms\View\Template $this
 * @var string $location
 * @var array<string, string> $locations
 * @var string $locale
 * @var list<AtarCms\Content\TreeNode> $tree
 * @var array<int, array{label: string, url: string|null, meta: string, hidden: bool, actions: array{add: string|null, edit: string|null, delete: string|null}}> $items
 * @var string $version
 * @var int $maxDepth
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 */
$title = $this->label($locations[$location]);
$newUrl = $this->url('admin.menus.new', ['location' => $location, 'locale' => $locale]);
$this->layout('layouts/admin', [
    'title' => $title . ' · ' . $this->t('admin.menus.title'),
    'breadcrumbs' => [[$this->t('admin.menus.title')], [$title]],
    'scripts' => ['dialog.js'],
    'toasts' => AtarCms\Admin\FlashMessage::errors($errors, $params),
    'contentLocale' => $locale,
]);
?>
<?= $this->partial('partials/page-header', [
    'title' => $title,
    'locale' => $locale,
    'actions' => [['label' => $this->t('admin.menus.new'), 'url' => $newUrl, 'icon' => 'plus', 'primary' => true, 'dialog' => true]],
]) ?>
<?php if (count($locations) > 1): ?>
<nav class="admin-switch admin-list-switch" aria-label="<?= $this->e($this->t('admin.menus.locations')) ?>">
    <?php foreach ($locations as $code => $label): ?>
    <?php if ($code === $location): ?>
    <span aria-current="page"><?= $this->e($this->label($label)) ?></span>
    <?php else: ?>
    <a href="<?= $this->e($this->url('admin.menus.show', ['location' => $code, 'locale' => $locale])) ?>"><?= $this->e($this->label($label)) ?></a>
    <?php endif ?>
    <?php endforeach ?>
</nav>
<?php endif ?>
<p class="admin-muted admin-list-count"><?= $this->e($this->t('admin.menus.count', ['count' => count($items)])) ?></p>
<?php if ($tree === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon('list-tree') ?>
    <p class="admin-empty-title"><?= $this->e($this->t('admin.menus.empty')) ?></p>
    <a class="admin-button admin-button-primary" href="<?= $this->e($newUrl) ?>" data-dialog-open><?= $this->icon('plus') ?><?= $this->e($this->t('admin.menus.new')) ?></a>
</div>
<?php else: ?>
<?= $this->partial('tree/editor', [
    'action' => $this->url('admin.menus.order', ['location' => $location]),
    'locale' => $locale,
    'version' => $version,
    'tree' => $tree,
    'items' => $items,
    'maxDepth' => $maxDepth,
    'countKey' => 'admin.menus.children',
    'addKey' => 'admin.menus.add_inside',
]) ?>
<?php endif ?>
<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">
    <div class="admin-dialog-content" data-dialog-content></div>
</dialog>
