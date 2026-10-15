<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Content\Taxonomy $taxonomy */
/** @var string $locale */
/** @var list<AtarCms\Content\TreeNode> $tree */
/** @var array<int, array{label: string, url: string|null, meta: string, hidden: bool}> $items */
/** @var string $version */
/** @var int $maxDepth */
/** @var array<string, string> $errors */
/** @var array<string, array<string, int|float|string>> $params */
$title = $this->t('admin.terms.order_title', ['taxonomy' => $this->label($taxonomy->label)]);
$this->layout('layouts/admin', [
    'title' => $title,
    'breadcrumbs' => [[$this->label($taxonomy->label), $this->url('admin.terms', ['taxonomy' => $taxonomy->name, 'locale' => $locale])], [$this->t('admin.terms.order')]],
    'toasts' => AtarCms\Admin\FlashMessage::errors($errors, $params),
    'contentLocale' => $locale,
]);
?>
<?= $this->partial('partials/page-header', ['title' => $title, 'locale' => $locale]) ?>
<?php if ($tree === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon('list-tree') ?>
    <p class="admin-empty-title"><?= $this->e($this->t('admin.tree.empty')) ?></p>
</div>
<?php else: ?>
<?= $this->partial('tree/editor', [
    'action' => $this->url('admin.terms.order.save', ['taxonomy' => $taxonomy->name]),
    'locale' => $locale,
    'version' => $version,
    'tree' => $tree,
    'items' => $items,
    'maxDepth' => $maxDepth,
]) ?>
<?php endif ?>
