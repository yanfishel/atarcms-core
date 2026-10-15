<?php
/**
 * The tree editor: a POST form with a hidden JSON "tree" input that both SortableJS (tree.js) and the arrow
 * buttons (tree/nodes.php) keep in sync with the visible order, plus "version" and "locale" for the controller
 * to check against the stored order. Used by the menu screen, the page and term order screens and the Sections
 * screen, which only need to render this partial with their own $action/$tree/$items. The arrow buttons save at
 * once (a form post, its result is a flash); tree.js saves a drag in the background (TreeResponder) and shows the
 * result as a toast cloned from the form's two <template>s: "Order saved." or the reason, with "Reload the page".
 * tree-open.js, a classic (parser-blocking) script right before the list, opens the accordion's remembered items as
 * the parser inserts them, so the first paint already shows them open (no layout shift).
 *
 * @var AtarCms\View\Template $this
 * @var string $action the form's POST target
 * @var string $locale
 * @var string $version the tree version the page was loaded at (TreeOrder::version())
 * @var list<AtarCms\Content\TreeNode> $tree
 * @var array<int, array{label: string, url: string|null, meta: string, hidden: bool, pinned?: bool, actions?: array{add: string|null, edit: string|null, delete: string|null}, locked?: array<string, string>}> $items id => how to render it
 * @var int $maxDepth
 * @var string|null $countKey (optional) the ICU message of an item's number of children ("# nested items" by default;
 *      the Sections screen says subsections); data-tree-counts gives tree.js its plural forms in the interface language
 * @var string|null $addKey (optional) the ICU message naming a row's "Add inside" ("Add a subsection to …" by default;
 *      the menu screen says subitem)
 * @var bool $flat (optional) a list without nesting (the articles of a section; tree/nodes.php): pass $maxDepth 1
 */
$countKey ??= 'admin.tree.children';
$addKey ??= 'admin.tree.action.add';
$flat ??= false;
$counts = AtarCms\I18n\PluralForms::of($this->locale(), fn(int $count): string => $this->t($countKey, ['count' => $count]));
?>
<form class="admin-tree-form" method="post" action="<?= $this->e($action) ?>" data-tree-form data-max-depth="<?= $this->e($maxDepth) ?>" data-tree-counts="<?= $this->e(json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
    <?= $this->csrfField() ?>
    <input type="hidden" name="version" value="<?= $this->e($version) ?>" data-tree-version>
    <input type="hidden" name="locale" value="<?= $this->e($locale) ?>">
    <input type="hidden" name="tree" value="<?= $this->e(AtarCms\Content\TreeOrder::toJson($tree)) ?>" data-tree-input>
    <script src="<?= $this->e($this->asset('tree-open.js')) ?>" nonce="<?= $this->e($this->nonce()) ?>"></script>
    <?= $this->partial('tree/nodes', ['nodes' => $tree, 'items' => $items, 'countKey' => $countKey, 'addKey' => $addKey, 'flat' => $flat]) ?>
    <template data-tree-saved><?= trim($this->partial('partials/toast', ['type' => 'success', 'text' => $this->t('admin.tree.flash.saved')])) ?></template>
    <template data-tree-failed><?= trim($this->partial('partials/toast', ['type' => 'error', 'text' => $this->t('admin.tree.status.failed'), 'action' => $this->t('admin.tree.reload')])) ?></template>
</form>
<script type="module" src="<?= $this->e($this->asset('tree.js')) ?>" nonce="<?= $this->e($this->nonce()) ?>"></script>
