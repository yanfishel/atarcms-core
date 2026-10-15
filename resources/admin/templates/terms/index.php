<?php
/**
 * The terms of a taxonomy (not the sections, which have their own screen) in one language, as a list like the
 * users': "Add new", a row's name and its Edit and Delete open the term pages, which dialog.js loads into the screen's
 * dialog (data-dialog-open); without JavaScript they are full pages. "Used in" is a count pill, the languages the
 * theme's flags; a flat taxonomy searches its names (?q=, the entries list's filter form). A taxonomy filled only by
 * tags fields (Collections::onlyTagged()) offers no "Add new": tags are added
 * in the entries' tags input (its form stays reachable by its address).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @var string $locale
 * @var list<string> $locales
 * @var list<array{term: AtarCms\Content\Term, depth: int}> $nodes
 * @var array<int, int> $counts
 * @var bool $canAdd
 * @var string $query the name search of a flat taxonomy's list (?q=; always '' for a tree)
 * @var array<string, list<string>> $groupLocales
 * @var list<AtarCms\Admin\FlashMessage> $toasts the offer to translate a term after a switch of the content language (?translate=)
 */
$title = $this->label($taxonomy->label);
$newUrl = $this->url('admin.terms.new', ['taxonomy' => $taxonomy->name, 'locale' => $locale]);
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$title]], 'contentLocale' => $locale, 'toasts' => $toasts, 'scripts' => ['dialog.js', ...($taxonomy->hierarchical ? [] : ['list-filters.js']), ...($this->multilingual() ? ['translate.js'] : [])]]);
$listUrl = $this->url('admin.terms', ['taxonomy' => $taxonomy->name]);
// Tags are kept separately per language (Collections::onlyTagged(), $canAdd false): no Languages column.
// The linked mode has no Languages column (Plan 37: which translations exist is the editors' concern).
$languages = $this->multilingual() && $canAdd && !$this->linkedLanguage();
?>
<?= $this->partial('partials/page-header', [
    'title' => $title,
    'locale' => $locale,
    'actions' => [
        ...($taxonomy->hierarchical ? [['label' => $this->t('admin.terms.order'), 'url' => $this->url('admin.terms.order', ['taxonomy' => $taxonomy->name, 'locale' => $locale]), 'icon' => 'list-tree']] : []),
        ...($canAdd ? [['label' => $this->t('admin.terms.new'), 'url' => $newUrl, 'icon' => 'plus', 'primary' => true, 'dialog' => true]] : []),
    ],
]) ?>
<?php if (!$taxonomy->hierarchical): ?>
<?php // The entries list's filter form: list-filters.js submits it after typing; htmx swaps only #terms-results, so the search box keeps its focus. ?>
<form class="admin-filters" method="get" action="<?= $this->e($listUrl) ?>" hx-get="<?= $this->e($listUrl) ?>" hx-target="#terms-results" hx-select="#terms-results" hx-swap="outerHTML" hx-push-url="true" hx-sync="this:replace" role="search" data-list-filters>
    <label class="admin-filter admin-filter-search" for="terms-q"><span class="admin-filter-label"><?= $this->e($this->t('admin.terms.filter.search')) ?></span>
        <?= $this->icon('search') ?><input type="search" id="terms-q" name="q" maxlength="<?= AtarCms\Admin\Taxonomy\TermsController::MAX_SEARCH ?>" value="<?= $this->e($query) ?>" data-list-search>
    </label>
    <button type="submit" class="admin-button admin-button-secondary admin-list-apply"><?= $this->icon('filter') ?><?= $this->e($this->t('admin.entries.filter.apply')) ?></button>
</form>
<?php endif ?>
<div id="terms-results">
<p class="admin-muted admin-list-count"><?= $this->e($this->t('admin.terms.count', ['count' => count($nodes)])) ?></p>
<?php if ($nodes === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon($query !== '' ? 'search' : ($taxonomy->hierarchical ? 'folder-tree' : 'tag')) ?>
    <p class="admin-empty-title"><?= $this->e($query !== '' ? $this->t('admin.empty.filtered') : $this->t('admin.empty.title')) ?></p>
    <?php if ($canAdd && $query === ''): ?>
    <a class="admin-button admin-button-primary" href="<?= $this->e($newUrl) ?>" data-dialog-open><?= $this->icon('plus') ?><?= $this->e($this->t('admin.empty.create')) ?></a>
    <?php endif ?>
</div>
<?php else: ?>
<div class="admin-card admin-table-card">
    <table class="admin-table">
        <thead>
            <tr>
                <th><?= $this->e($this->t('admin.terms.column.name')) ?></th>
                <th><?= $this->e($this->t('admin.terms.column.slug')) ?></th>
                <th><?= $this->e($this->t('admin.terms.column.entries')) ?></th>
                <?php if ($languages): ?>
                <th><?= $this->e($this->t('admin.terms.column.languages')) ?></th>
                <?php endif ?>
                <th><span class="admin-visually-hidden"><?= $this->e($this->t('admin.terms.column.actions')) ?></span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($nodes as $node): ?>
            <?php $term = $node['term']; ?>
            <?php $editUrl = $this->url('admin.terms.edit', ['taxonomy' => $taxonomy->name, 'id' => $term->id]); ?>
            <?php $editLabel = $this->t('admin.tree.action.edit', ['label' => $term->name]); ?>
            <?php $deleteLabel = $this->t('admin.tree.action.delete', ['label' => $term->name]); ?>
            <?php $uses = $counts[$term->id] ?? 0; ?>
            <tr class="admin-table-row">
                <td class="admin-depth-<?= $this->e(min($node['depth'], 5)) ?>" data-label="<?= $this->e($this->t('admin.terms.column.name')) ?>"><a class="admin-row-link" href="<?= $this->e($editUrl) ?>" data-dialog-open><?= $this->e($term->name) ?></a></td>
                <td class="admin-muted" data-label="<?= $this->e($this->t('admin.terms.column.slug')) ?>"><?= $this->e($term->slug) ?></td>
                <td data-label="<?= $this->e($this->t('admin.terms.column.entries')) ?>"><?php if ($uses > 0): ?><span class="admin-uses" title="<?= $this->e($this->t('admin.terms.used_count', ['count' => $uses])) ?>"><?= $this->e($uses) ?></span><?php endif ?></td>
                <?php if ($languages): ?>
                <td data-label="<?= $this->e($this->t('admin.terms.column.languages')) ?>"><?= trim($this->partial('partials/flags', ['codes' => $groupLocales[$term->translationGroup] ?? [$term->locale]])) ?></td>
                <?php endif ?>
                <td class="admin-table-actions"><span class="admin-row-actions">
                    <a class="admin-row-action" href="<?= $this->e($editUrl) ?>" title="<?= $this->e($editLabel) ?>" aria-label="<?= $this->e($editLabel) ?>" data-dialog-open><?= $this->icon('pencil') ?></a>
                    <a class="admin-row-action admin-row-action-danger" href="<?= $this->e($this->url('admin.terms.delete.confirm', ['taxonomy' => $taxonomy->name, 'id' => $term->id])) ?>" title="<?= $this->e($deleteLabel) ?>" aria-label="<?= $this->e($deleteLabel) ?>" data-dialog-open><?= $this->icon('trash-2') ?></a>
                </span></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
</div>
<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">
    <div class="admin-dialog-content" data-dialog-content></div>
</dialog>
