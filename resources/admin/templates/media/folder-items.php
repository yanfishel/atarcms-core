<?php
/**
 * The current folder's subfolders at the start of the library's and the picker's results: tiles in the grid, rows
 * in the list, plain navigation only (no checkboxes — folders are managed from the sidebar's tree, never here).
 * The caller shows them only on the first page of a plain browse (no search, not the unused filter).
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Media\MediaFolder> $folders
 * @var string $view "grid" or "list"
 * @var callable(int|null): string $url a folder => its URL
 * @var string|null $target (optional) the hx-target of the links (the picker)
 * @var int $cells trailing empty cells after the Type column (list view)
 */
$target ??= null;
$hx = fn(string $href): string => $target === null ? '' : ' hx-get="' . $href . '" hx-target="' . $this->e($target) . '" hx-swap="outerHTML"';
?>
<?php if ($view === 'list'): ?>
<?php foreach ($folders as $folder): ?>
<?php $href = $this->e($url($folder->id)); ?>
<tr class="admin-table-row">
    <td class="admin-media-cell-check"></td>
    <td class="admin-media-cell-thumb"><span class="admin-media-thumb admin-media-file admin-media-folder-thumb"><?= $this->icon('folder') ?></span></td>
    <td class="admin-media-cell-name" data-label="<?= $this->e($this->t('admin.media.column.name')) ?>"><a class="admin-media-name admin-media-folder-name" href="<?= $href ?>"<?= $hx($href) ?>><?= $this->e($folder->name) ?></a></td>
    <td data-label="<?= $this->e($this->t('admin.media.column.type')) ?>"><?= $this->e($this->t('admin.media.folders.folder')) ?></td>
    <?php for ($cell = 0; $cell < $cells; ++$cell): ?><td></td><?php endfor ?>
</tr>
<?php endforeach ?>
<?php else: ?>
<?php foreach ($folders as $folder): ?>
<?php $href = $this->e($url($folder->id)); ?>
<li class="admin-media-grid-item">
    <a class="admin-media-card admin-media-folder-tile" href="<?= $href ?>"<?= $hx($href) ?>>
        <span class="admin-media-thumb admin-media-file admin-media-folder-thumb"><?= $this->icon('folder') ?></span>
        <span class="admin-media-name" title="<?= $this->e($folder->name) ?>"><?= $this->e($folder->name) ?></span>
        <span class="admin-media-size"><?= $this->e($this->t('admin.media.folders.folder')) ?></span>
    </a>
</li>
<?php endforeach ?>
<?php endif ?>
