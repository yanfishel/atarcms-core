<?php
/**
 * The library's results (#media-results): the count, the files as tiles or a table, the pages and the bulk bar. The
 * toolbar's htmx requests and the uploads swap only this element, so the toolbar keeps its state and focus.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\MediaFolderTree $tree
 * @var AtarCms\Admin\Media\MediaListQuery $list
 * @var AtarCms\Media\MediaPage $page
 * @var array<int, int> $usage media id => uses (list view only)
 * @var int $uploaded
 * @var list<array{key: string, params: array<string, int|float|string>}> $failures
 * @var array<string, string> $labels MIME type => its short name
 */
$pageUrl = fn(int $number): string => $this->url('admin.media', $list->withPage($number)->params());
// The current folder's subfolders open the results, file-manager style, on the first page of a plain browse.
$folders = $list->search === '' && !$list->unused && $list->page === 1 ? $tree->children($list->folder) : [];
$folderItems = fn(string $view): string => $this->partial('media/folder-items', [
    'folders' => $folders,
    'view' => $view,
    'url' => fn(?int $id): string => $this->url('admin.media', $list->withFolder($id)->params()),
    'cells' => 5,
]);
?>
<div id="media-results" class="admin-media-results" data-media-results>
    <?php if ($uploaded > 0 || $failures !== []): ?>
    <template data-upload-toast><?php if ($uploaded > 0): ?><?= trim($this->partial('partials/toast', ['type' => 'success', 'text' => $this->t('admin.media.flash.uploaded', ['count' => $uploaded])])) ?><?php endif ?><?php foreach ($failures as $failure): ?><?= trim($this->partial('partials/toast', ['type' => 'error', 'text' => $this->t($failure['key'], $failure['params'])])) ?><?php endforeach ?></template>
    <?php endif ?>
    <?= $this->partial('media/count', ['list' => $list, 'total' => $page->total]) ?>
    <?php if ($page->items === [] && $folders === []): ?>
    <div class="admin-card admin-empty">
        <?= $this->icon('image') ?>
        <p class="admin-empty-title"><?= $this->e($this->t('admin.media.empty')) ?></p>
    </div>
    <?php elseif ($list->view === 'list'): ?>
    <div class="admin-card admin-table-card">
        <table class="admin-table admin-media-table">
            <thead>
                <tr>
                    <th scope="col" class="admin-media-cell-check"><span class="admin-visually-hidden"><?= $this->e($this->t('admin.media.column.select')) ?></span></th>
                    <th scope="col" class="admin-media-cell-thumb"><span class="admin-visually-hidden"><?= $this->e($this->t('admin.media.column.preview')) ?></span></th>
                    <th scope="col"><?= $this->e($this->t('admin.media.column.name')) ?></th>
                    <th scope="col"><?= $this->e($this->t('admin.media.column.type')) ?></th>
                    <th scope="col"><?= $this->e($this->t('admin.media.column.size')) ?></th>
                    <th scope="col"><?= $this->e($this->t('admin.media.column.dimensions')) ?></th>
                    <th scope="col"><?= $this->e($this->t('admin.media.column.uploaded')) ?></th>
                    <th scope="col"><?= $this->e($this->t('admin.media.column.used')) ?></th>
                    <th scope="col" class="admin-media-cell-actions"><span class="admin-visually-hidden"><?= $this->e($this->t('admin.media.column.open')) ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?= $folderItems('list') ?>
                <?php foreach ($page->items as $item): ?>
                <?php $uses = $usage[$item->id] ?? 0; ?>
                <tr class="admin-table-row" data-media-row>
                    <td class="admin-media-cell-check"><?= $this->partial('media/check', ['media' => $item]) ?></td>
                    <td class="admin-media-cell-thumb"><?= $this->partial('media/thumb', ['media' => $item]) ?></td>
                    <td class="admin-media-cell-name" data-label="<?= $this->e($this->t('admin.media.column.name')) ?>"><label class="admin-media-name" title="<?= $this->e($item->originalName) ?>" for="media-check-<?= $this->e($item->id) ?>"><?= $this->e($item->originalName) ?></label></td>
                    <td data-label="<?= $this->e($this->t('admin.media.column.type')) ?>"><?= $this->e($labels[$item->mime] ?? $item->mime) ?></td>
                    <td data-label="<?= $this->e($this->t('admin.media.column.size')) ?>"><?= $this->e($this->t('admin.media.details.size_value', ['kb' => round($item->size / 1024, 1)])) ?></td>
                    <td data-label="<?= $this->e($this->t('admin.media.column.dimensions')) ?>"><?= $item->width !== null && $item->height !== null ? $this->e($this->t('admin.media.details.dimensions_value', ['width' => $item->width, 'height' => $item->height])) : '' ?></td>
                    <td class="admin-media-cell-date" data-label="<?= $this->e($this->t('admin.media.column.uploaded')) ?>"><?= trim($this->partial('partials/table-date', ['time' => $item->createdAt])) ?></td>
                    <td data-label="<?= $this->e($this->t('admin.media.column.used')) ?>"><?php if ($uses > 0): ?><span class="admin-media-uses" title="<?= $this->e($this->t('admin.media.used_count', ['count' => $uses])) ?>"><?= $this->e($uses) ?></span><?php endif ?></td>
                    <td class="admin-media-cell-actions"><?= $this->partial('media/open', ['media' => $item]) ?></td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <ul class="admin-media-grid">
        <?= $folderItems('grid') ?>
        <?php foreach ($page->items as $item): ?>
        <li class="admin-media-grid-item">
            <label class="admin-media-card" for="media-check-<?= $this->e($item->id) ?>">
                <?= $this->partial('media/thumb', ['media' => $item]) ?>
                <span class="admin-media-name" title="<?= $this->e($item->originalName) ?>"><?= $this->e($item->originalName) ?></span>
                <span class="admin-media-size"><?= $this->e($this->t('admin.media.details.size_value', ['kb' => round($item->size / 1024, 1)])) ?></span>
            </label>
            <?= $this->partial('media/check', ['media' => $item]) ?>
            <?= $this->partial('media/open', ['media' => $item]) ?>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
    <?= $this->partial('partials/pagination', ['page' => $page->number, 'pages' => $page->lastPage(), 'url' => $pageUrl]) ?>
    <?php if ($page->items !== []): ?>
    <form id="media-bulk" class="admin-action-bar admin-media-bulk" method="post" action="<?= $this->e($this->url('admin.media.move')) ?>" data-media-bulk data-selected="<?= $this->e($this->t('admin.media.bulk.selected', ['count' => '#'])) ?>">
        <?= $this->csrfField() ?>
        <?php foreach ($list->params() as $name => $value): ?>
        <input type="hidden" name="back[<?= $this->e($name) ?>]" value="<?= $this->e($value) ?>">
        <?php endforeach ?>
        <p class="admin-action-bar-status admin-media-bulk-count" data-media-bulk-count><?= $this->e($this->t('admin.media.bulk.title')) ?></p>
        <div class="admin-action-bar-buttons">
            <label class="admin-media-bulk-label" for="media-target"><?= $this->e($this->t('admin.media.bulk.move_to')) ?></label>
            <select id="media-target" class="admin-media-bulk-target" name="target">
                <option value=""><?= $this->e($this->t('admin.media.folders.root')) ?></option>
                <?php foreach ($tree->options() as $option): ?>
                <option value="<?= $this->e($option['folder']->id) ?>"><?= $this->e(str_repeat('— ', $option['depth'] + 1) . $option['folder']->name) ?></option>
                <?php endforeach ?>
            </select>
            <button type="submit" class="admin-button admin-button-secondary" data-media-bulk-action><?= $this->icon('folder-input') ?><?= $this->e($this->t('admin.media.bulk.move')) ?></button>
            <?php if ($this->can('media.delete')): ?>
            <button type="submit" class="admin-button admin-button-link admin-button-danger" formaction="<?= $this->e($this->url('admin.media.delete.many')) ?>" data-media-bulk-action><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.media.bulk.delete')) ?></button>
            <?php endif ?>
        </div>
    </form>
    <?php endif ?>
</div>
