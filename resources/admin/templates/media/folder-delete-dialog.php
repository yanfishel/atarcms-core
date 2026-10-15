<?php
/**
 * The folder delete confirmation as the dialog's content (Dialog, dialog.js): Cancel (focused) closes the dialog,
 * Delete posts through htmx; a done or refused delete reloads the library with a toast.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\MediaFolder $folder
 */
$delete = $this->url('admin.media.folders.delete', ['id' => $folder->id]);
?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($this->t('admin.media.folders.delete_title')) ?></h2>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<form class="admin-dialog-form" method="post" action="<?= $this->e($delete) ?>" hx-post="<?= $this->e($delete) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
    <div class="admin-dialog-body">
        <p><?= $this->e($this->t('admin.media.folders.delete_confirm', ['name' => $folder->name])) ?></p>
        <?= $this->csrfField() ?>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close autofocus><?= $this->e($this->t('admin.media.cancel')) ?></button>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.media.folders.delete_button')) ?></button>
    </div>
</form>
