<?php
/**
 * The media library: the folder tree in a sticky side column beside the page; on the right the heading, the drop zone
 * (uploads go into the folder shown), the toolbar and the results. Folder pages open in the page's dialog (dialog.js).
 *
 * @var AtarCms\View\Template $this
 * @var array<string, mixed> $library the data of media/library
 * @var AtarCms\Media\MediaFolderTree $tree
 * @var AtarCms\Admin\Media\MediaListQuery $list
 * @var string $accept
 * @var array{key: string, params: array<string, float|string>} $help the upload help line (its key and parameters)
 */
$title = $this->t('admin.media.title');
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$title]], 'scripts' => ['media.js', 'dialog.js']]);
$store = $this->url('admin.media.store');
$folderUrl = fn(?int $folder): string => $this->url('admin.media', $list->withFolder($folder)->params());
?>
<div class="admin-media-browser admin-media-page">
    <div class="admin-media-head"><?= $this->partial('partials/page-header', ['title' => $title]) ?></div>
    <aside class="admin-card admin-media-sidebar">
        <h2 class="admin-card-heading"><?= $this->e($this->t('admin.media.folders.heading')) ?></h2>
        <?= $this->partial('media/folders', ['tree' => $tree, 'current' => $list->folder, 'url' => $folderUrl, 'editable' => true]) ?>
        <a class="admin-button admin-button-link admin-media-new-folder" href="<?= $this->e($this->url('admin.media.folders.create', $list->folder === null ? [] : ['parent' => $list->folder])) ?>" data-dialog-open><?= $this->icon('folder-plus') ?><?= $this->e($this->t('admin.media.folders.new')) ?></a>
    </aside>
    <div class="admin-media-content">
        <form class="admin-dropzone" method="post" action="<?= $this->e($store) ?>" enctype="multipart/form-data" hx-post="<?= $this->e($store) ?>" hx-encoding="multipart/form-data" hx-target="#media-results" hx-swap="outerHTML" hx-include="#media-filters" data-media-dropzone>
            <?= $this->csrfField() ?>
            <input type="hidden" name="folder" value="<?= $this->e($list->folder ?? '') ?>">
            <?= $this->icon('upload') ?>
            <p class="admin-dropzone-title"><?= $this->e($this->t('admin.media.upload.drop')) ?></p>
            <label class="admin-button admin-button-secondary admin-dropzone-label" for="media-files"><?= $this->e($this->t('admin.media.upload.label')) ?></label>
            <input type="file" id="media-files" name="files[]" multiple required accept="<?= $this->e($accept) ?>">
            <span class="admin-help"><?= $this->e($this->t($help['key'], $help['params'])) ?></span>
            <progress class="admin-progress" max="100" value="0" hidden data-upload-progress><?= $this->e($this->t('admin.media.upload.progress')) ?></progress>
            <button type="submit" class="admin-button admin-button-primary" data-upload-submit><?= $this->icon('upload') ?><?= $this->e($this->t('admin.media.upload.submit')) ?></button>
            <template data-upload-failed><?= trim($this->partial('partials/toast', ['type' => 'error', 'text' => $this->t('admin.media.upload.failed')])) ?></template>
        </form>
        <?= $this->partial('media/toolbar', ['tree' => $tree, 'list' => $list, 'picker' => false, 'action' => $this->url('admin.media'), 'results' => '#media-results', 'folderUrl' => $folderUrl]) ?>
        <?= $this->partial('media/library', $library) ?>
    </div>
</div>
<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">
    <div class="admin-dialog-content" data-dialog-content></div>
</dialog>
