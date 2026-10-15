<?php
/**
 * The map's removal as the dialog's content (Dialog, dialog.js): Cancel (focused) closes the dialog, Remove posts
 * through htmx and its answer replaces the entry form's map column.
 *
 * @var AtarCms\View\Template $this
 */
$delete = $this->url('admin.map.delete');
?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($this->t('admin.map.delete_title')) ?></h2>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<form class="admin-dialog-form" method="post" action="<?= $this->e($delete) ?>" hx-post="<?= $this->e($delete) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
    <div class="admin-dialog-body">
        <p><?= $this->e($this->t('admin.map.delete_confirm')) ?></p>
        <?= $this->csrfField() ?>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close autofocus><?= $this->e($this->t('admin.map.cancel')) ?></button>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.map.remove')) ?></button>
    </div>
</form>
