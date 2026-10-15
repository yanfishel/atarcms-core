<?php
/**
 * The delete confirmation as the dialog's content (Dialog, dialog.js): Cancel (focused) closes the dialog, Delete
 * posts through htmx and a done or refused delete reloads the users list with the flash.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Auth\User $user
 */
$delete = $this->url('admin.users.delete', ['id' => $user->id]);
?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($this->t('admin.users.delete_title')) ?></h2>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<form class="admin-dialog-form" method="post" action="<?= $this->e($delete) ?>" hx-post="<?= $this->e($delete) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
    <div class="admin-dialog-body">
        <p><?= $this->e($this->t('admin.users.delete_confirm', ['email' => $user->email])) ?></p>
        <?= $this->csrfField() ?>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close autofocus><?= $this->e($this->t('admin.users.cancel')) ?></button>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.users.delete_button')) ?></button>
    </div>
</form>
