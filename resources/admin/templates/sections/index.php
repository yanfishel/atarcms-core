<?php
/**
 * The Sections screen: the sections of one language in the tree editor, with the main menu's pinned items among them
 * (they move only up and down at the top level). The header names the language (its pill; the top bar switches it) and ends
 * with "Add section", as on the other list screens. It and each row's Edit and Delete open the term pages under /admin/sections, which dialog.js loads into the
 * screen's dialog (data-dialog-open); without JavaScript they are full pages. tabs.js sets up the section form's
 * "Main | SEO" tabs when the dialog has loaded it. A row's first slot, "Order articles" (sections/articles.php), is a
 * full page.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @var string $locale
 * @var list<AtarCms\Content\TreeNode> $tree
 * @var array<int, array{label: string, url: string|null, meta: string, hidden: bool, pinned: bool, actions: array{order?: string|null, add: string|null, edit: string|null, delete: string|null}, locked: array<string, string>}> $items
 * @var string $version
 * @var int $maxDepth
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var list<AtarCms\Admin\FlashMessage> $toasts the offer to translate a section after a switch of the content language (?translate=)
 */
$title = $this->label($taxonomy->label);
$newUrl = $this->url('admin.sections.new', ['locale' => $locale]);
$this->layout('layouts/admin', [
    'title' => $title,
    'breadcrumbs' => [[$title]],
    'scripts' => ['dialog.js', 'tabs.js', ...($this->multilingual() ? ['translate.js'] : [])],
    'toasts' => [...AtarCms\Admin\FlashMessage::errors($errors, $params), ...$toasts],
    'contentLocale' => $locale,
]);
?>
<?= $this->partial('partials/page-header', [
    'title' => $title,
    'locale' => $locale,
    'actions' => [['label' => $this->t('admin.sections.new'), 'url' => $newUrl, 'icon' => 'plus', 'primary' => true, 'dialog' => true]],
]) ?>
<p class="admin-muted admin-list-count"><?= $this->e($this->t('admin.sections.help')) ?></p>
<?php if ($tree === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon('folder-tree') ?>
    <p class="admin-empty-title"><?= $this->e($this->t('admin.sections.empty')) ?></p>
    <a class="admin-button admin-button-primary" href="<?= $this->e($newUrl) ?>" data-dialog-open><?= $this->icon('plus') ?><?= $this->e($this->t('admin.sections.new')) ?></a>
</div>
<?php else: ?>
<?= $this->partial('tree/editor', [
    'action' => $this->url('admin.sections.order'),
    'locale' => $locale,
    'version' => $version,
    'tree' => $tree,
    'items' => $items,
    'maxDepth' => $maxDepth,
    'countKey' => 'admin.sections.children',
]) ?>
<?php endif ?>
<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">
    <div class="admin-dialog-content" data-dialog-content></div>
</dialog>
