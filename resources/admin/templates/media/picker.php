<?php
/**
 * The picker dialog's content (loaded by media.js into [data-media-dialog-body]): the folder tree, the path, filters,
 * the grid/list switch and the files, as toggle buttons media.js selects (data-url and data-alt for the rich-text
 * editor); folders, filters, pages and uploads replace it through htmx. Uploads go into the folder shown and the new
 * files come marked (data-uploaded) for media.js to select. The picker never manages folders.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\MediaFolderTree $tree
 * @var AtarCms\Admin\Media\MediaListQuery $list
 * @var AtarCms\Media\MediaPage $page
 * @var int $uploaded
 * @var list<int> $uploadedIds the files just uploaded that the picker may choose
 * @var list<array{key: string, params: array<string, int|float|string>}> $failures
 * @var array<string, string> $labels MIME type => its short name
 * @var string $accept
 * @var array{key: string, params: array<string, float|string>} $help the upload help line (its key and parameters)
 */
$target = 'closest [data-media-picker]';
$route = fn(array $params): string => $this->url('admin.media.picker', $params);
$folderUrl = fn(?int $folder): string => $route($list->withFolder($folder)->params(true));
$pageUrl = fn(int $number): string => $route($list->withPage($number)->params(true));
$store = $this->url('admin.media.store', ['context' => 'picker', ...array_intersect_key($list->params(true), array_flip(['type', 'kind', 'locale']))]);
// Each file is a checkbox, as in the library (media.js keeps the selection); the card or the name is its label.
$pick = function (AtarCms\Media\Media $item) use ($list, $uploadedIds): string {
    return '<input type="checkbox" id="pick-' . $this->e($item->id) . '" data-media-pick="' . $this->e($item->id) . '"'
        . ' data-name="' . $this->e($item->originalName) . '"'
        . ' data-thumb="' . $this->e($item->isImage() ? $this->mediaThumb($item) : '') . '"'
        . ' data-url="' . $this->e($this->mediaUrl($item)) . '"'
        . ' data-alt="' . $this->e($list->locale !== '' ? ($item->alt($list->locale) ?? '') : '') . '"'
        . (in_array($item->id, $uploadedIds, true) ? ' data-uploaded' : '') . '>';
};
// The current folder's subfolders open the results, for navigation only (a folder cannot be picked).
$folders = $list->search === '' && !$list->unused && $list->page === 1 ? $tree->children($list->folder) : [];
$folderItems = fn(string $view): string => $this->partial('media/folder-items', [
    'folders' => $folders,
    'view' => $view,
    'url' => $folderUrl,
    'target' => $target,
    'cells' => 3,
]);
?>
<div class="admin-media-picker admin-media-browser" data-media-picker>
    <?php if ($uploaded > 0 || $failures !== []): ?>
    <template data-upload-toast><?php if ($uploaded > 0): ?><?= trim($this->partial('partials/toast', ['type' => 'success', 'text' => $this->t('admin.media.flash.uploaded', ['count' => $uploaded])])) ?><?php endif ?><?php foreach ($failures as $failure): ?><?= trim($this->partial('partials/toast', ['type' => 'error', 'text' => $this->t($failure['key'], $failure['params'])])) ?><?php endforeach ?></template>
    <?php endif ?>
    <aside class="admin-card admin-media-sidebar">
        <h3 class="admin-card-heading"><?= $this->e($this->t('admin.media.folders.heading')) ?></h3>
        <?= $this->partial('media/folders', ['tree' => $tree, 'current' => $list->folder, 'url' => $folderUrl, 'target' => $target]) ?>
    </aside>
    <div class="admin-media-content">
        <form class="admin-dropzone admin-dropzone-compact" method="post" action="<?= $this->e($store) ?>" enctype="multipart/form-data" hx-post="<?= $this->e($store) ?>" hx-encoding="multipart/form-data" hx-target="<?= $this->e($target) ?>" hx-swap="outerHTML" data-media-dropzone>
            <?= $this->csrfField() ?>
            <input type="hidden" name="folder" value="<?= $this->e($list->folder ?? '') ?>">
            <label class="admin-button admin-button-secondary admin-dropzone-label" for="media-picker-files"><?= $this->icon('upload') ?><?= $this->e($this->t('admin.media.picker.upload')) ?></label>
            <input type="file" id="media-picker-files" name="files[]" multiple required accept="<?= $this->e($accept) ?>">
            <span class="admin-help"><?= $this->e($this->t($help['key'], $help['params'])) ?></span>
            <progress class="admin-progress" max="100" value="0" hidden data-upload-progress><?= $this->e($this->t('admin.media.upload.progress')) ?></progress>
            <button type="submit" class="admin-button admin-button-secondary" data-upload-submit><?= $this->e($this->t('admin.media.upload.submit')) ?></button>
            <template data-upload-failed><?= trim($this->partial('partials/toast', ['type' => 'error', 'text' => $this->t('admin.media.upload.failed')])) ?></template>
        </form>
        <?= $this->partial('media/toolbar', ['tree' => $tree, 'list' => $list, 'picker' => true, 'action' => $this->url('admin.media.picker'), 'results' => '#picker-results', 'folderUrl' => $folderUrl, 'target' => $target]) ?>
        <div id="picker-results" class="admin-media-results" data-media-results>
            <?= $this->partial('media/count', ['list' => $list, 'total' => $page->total]) ?>
            <?php if ($page->items === [] && $folders === []): ?>
            <div class="admin-empty">
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
                        </tr>
                    </thead>
                    <tbody>
                        <?= $folderItems('list') ?>
                        <?php foreach ($page->items as $item): ?>
                        <tr class="admin-table-row" data-media-row data-media-item>
                            <td class="admin-media-cell-check"><span class="admin-media-select"><?= $pick($item) ?></span></td>
                            <td class="admin-media-cell-thumb"><?= $this->partial('media/thumb', ['media' => $item]) ?></td>
                            <td class="admin-media-cell-name" data-label="<?= $this->e($this->t('admin.media.column.name')) ?>"><label class="admin-media-name admin-media-pick-name" title="<?= $this->e($item->originalName) ?>" for="pick-<?= $this->e($item->id) ?>" data-pick-info><?= $this->e($item->originalName) ?></label></td>
                            <td data-label="<?= $this->e($this->t('admin.media.column.type')) ?>"><?= $this->e($labels[$item->mime] ?? $item->mime) ?></td>
                            <td data-label="<?= $this->e($this->t('admin.media.column.size')) ?>"><?= $this->e($this->t('admin.media.details.size_value', ['kb' => round($item->size / 1024, 1)])) ?></td>
                            <td data-label="<?= $this->e($this->t('admin.media.column.dimensions')) ?>"><?= $item->width !== null && $item->height !== null ? $this->e($this->t('admin.media.details.dimensions_value', ['width' => $item->width, 'height' => $item->height])) : '' ?></td>
                            <td class="admin-media-cell-date" data-label="<?= $this->e($this->t('admin.media.column.uploaded')) ?>"><?= trim($this->partial('partials/table-date', ['time' => $item->createdAt])) ?></td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <ul class="admin-media-grid">
                <?= $folderItems('grid') ?>
                <?php foreach ($page->items as $item): ?>
                <li class="admin-media-grid-item" data-media-item>
                    <label class="admin-media-card" for="pick-<?= $this->e($item->id) ?>" data-pick-info>
                        <?= $this->partial('media/thumb', ['media' => $item]) ?>
                        <span class="admin-media-name" title="<?= $this->e($item->originalName) ?>"><?= $this->e($item->originalName) ?></span>
                        <span class="admin-media-size"><?= $this->e($this->t('admin.media.details.size_value', ['kb' => round($item->size / 1024, 1)])) ?></span>
                    </label>
                    <span class="admin-media-select"><?= $pick($item) ?></span>
                </li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
            <?= $this->partial('partials/pagination', ['page' => $page->number, 'pages' => $page->lastPage(), 'url' => $pageUrl, 'target' => $target]) ?>
        </div>
    </div>
</div>
