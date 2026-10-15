<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Content\Collection $collection */
/** @var string $locale */
/** @var list<AtarCms\Content\TreeNode> $tree */
/** @var array<int, array{label: string, url: string|null, meta: string, hidden: bool}> $items */
/** @var string $version */
/** @var int $maxDepth */
/** @var array<string, string> $errors */
/** @var array<string, array<string, int|float|string>> $params */
$title = $this->t('admin.entries.order_title', ['collection' => $this->label($collection->label)]);
$this->layout('layouts/admin', [
    'title' => $title,
    'breadcrumbs' => [[$this->label($collection->label), $this->url('admin.entries', ['collection' => $collection->name, 'locale' => $locale])], [$this->t('admin.entries.order')]],
    'toasts' => AtarCms\Admin\FlashMessage::errors($errors, $params),
    // A collection in one language leaves the top bar on the remembered language: switching does not concern it.
    ...($collection->translatable ? ['contentLocale' => $locale] : []),
]);
?>
<?= $this->partial('partials/page-header', ['title' => $title, 'locale' => $collection->translatable ? $locale : null]) ?>
<?php if ($tree === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon('list-tree') ?>
    <p class="admin-empty-title"><?= $this->e($this->t('admin.tree.empty')) ?></p>
</div>
<?php else: ?>
<?= $this->partial('tree/editor', [
    'action' => $this->url('admin.entries.order.save', ['collection' => $collection->name]),
    'locale' => $locale,
    'version' => $version,
    'tree' => $tree,
    'items' => $items,
    'maxDepth' => $maxDepth,
]) ?>
<?php endif ?>
