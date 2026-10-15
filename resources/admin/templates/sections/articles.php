<?php
/**
 * "Order articles" of a section: its articles of every status in its language, in the tree editor as a flat list
 * (up and down only), each with its status chip; a name opens the article's form where the user may edit it. The
 * header names the section's language; the breadcrumbs lead back to the Sections screen of that language.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @var AtarCms\Content\Term $section
 * @var list<AtarCms\Content\TreeNode> $tree
 * @var array<int, array{label: string, url: string|null, meta: string, hidden: bool, status: AtarCms\Content\EntryStatus}> $items
 * @var string $version
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 */
$title = $this->t('admin.sections.articles_title', ['section' => $section->name]);
$this->layout('layouts/admin', [
    'title' => $title,
    'breadcrumbs' => [[$this->label($taxonomy->label), $this->url('admin.sections', ['locale' => $section->locale])], [$section->name]],
    'toasts' => AtarCms\Admin\FlashMessage::errors($errors, $params),
    'contentLocale' => $section->locale,
]);
?>
<?= $this->partial('partials/page-header', ['title' => $title, 'locale' => $section->locale]) ?>
<?php if ($tree === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon('newspaper') ?>
    <p class="admin-empty-title"><?= $this->e($this->t('admin.sections.articles_empty')) ?></p>
</div>
<?php else: ?>
<p class="admin-muted admin-list-count"><?= $this->e($this->t('admin.sections.articles_help')) ?></p>
<?= $this->partial('tree/editor', [
    'action' => $this->url('admin.sections.articles.save', ['id' => $section->id]),
    'locale' => $section->locale,
    'version' => $version,
    'tree' => $tree,
    'items' => $items,
    'maxDepth' => 1,
    'flat' => true,
]) ?>
<?php endif ?>
