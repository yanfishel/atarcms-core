<?php
/**
 * The folder form as the dialog's content (Dialog, dialog.js): the heading names the dialog (#dialog-title), the form
 * posts through htmx and its answer replaces this fragment (field errors) or reloads the library (saved, or refused
 * without a field: a toast). Cancel closes the dialog.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\MediaFolder|null $folder null for a new folder
 * @var array{name: string, parent_id: int|null} $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var string $version
 * @var list<array{folder: AtarCms\Media\MediaFolder, depth: int}> $options
 * @var list<int> $excluded
 * @var array{key: string, params: array<string, int>}|null $deleteBlocked why it cannot be deleted
 */
$heading = $folder === null ? $this->t('admin.media.folders.new') : $this->t('admin.media.folders.settings');
$action = $folder === null ? $this->url('admin.media.folders.store') : $this->url('admin.media.folders.update', ['id' => $folder->id]);
$fieldErrors = array_intersect_key($errors, ['name' => true, 'parent_id' => true]);
?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($heading) ?></h2>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<form class="admin-dialog-form" method="post" action="<?= $this->e($action) ?>" hx-post="<?= $this->e($action) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
    <div class="admin-dialog-body">
        <?php if ($fieldErrors !== []): ?>
        <div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t('admin.form.fix_fields', ['count' => count($fieldErrors)])) ?></p></div>
        <?php endif ?>
        <?= $this->csrfField() ?>
        <?php if ($folder !== null): ?>
        <input type="hidden" name="version" value="<?= $this->e($version) ?>">
        <?php endif ?>
        <?= $this->partial('media/folder-fields', ['values' => $values, 'errors' => $errors, 'params' => $params, 'options' => $options, 'excluded' => $excluded]) ?>
    </div>
    <div class="admin-dialog-footer">
        <?php if ($folder !== null): ?>
        <span class="admin-media-dialog-delete"><?= $this->partial('media/folder-delete-button', ['folder' => $folder, 'deleteBlocked' => $deleteBlocked, 'dialog' => true]) ?></span>
        <?php endif ?>
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close><?= $this->e($this->t('admin.media.cancel')) ?></button>
        <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($this->t('admin.media.folders.save')) ?></button>
    </div>
</form>
