<?php
/**
 * A folder's form: its name and the folder it sits in (the folder itself and its subfolders cannot be chosen).
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
 * @var list<AtarCms\Media\MediaFolder> $trail the folder's trail (a new folder: its parent's)
 */
$heading = $folder === null ? $this->t('admin.media.folders.new') : $folder->name;
$action = $folder === null ? $this->url('admin.media.folders.store') : $this->url('admin.media.folders.update', ['id' => $folder->id]);
$breadcrumbs = [[$this->t('admin.media.title'), $this->url('admin.media')]];
foreach ($trail as $step) {
    if ($folder === null || $step->id !== $folder->id) {
        $breadcrumbs[] = [$step->name, $this->url('admin.media', ['folder' => $step->id])];
    }
}
$breadcrumbs[] = [$heading];
$fieldErrors = array_intersect_key($errors, ['name' => true, 'parent_id' => true]);
$this->layout('layouts/admin', [
    'title' => $folder === null ? $heading : $this->t('admin.media.folders.edit') . ' · ' . $folder->name,
    'breadcrumbs' => $breadcrumbs,
    'toasts' => AtarCms\Admin\FlashMessage::errors(array_diff_key($errors, $fieldErrors), $params),
]);
?>
<?= $this->partial('partials/page-header', ['title' => $heading]) ?>
<?php if ($fieldErrors !== []): ?>
<div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t('admin.form.fix_fields', ['count' => count($fieldErrors)])) ?></p></div>
<?php endif ?>
<form class="admin-form-card" method="post" action="<?= $this->e($action) ?>">
    <?= $this->csrfField() ?>
    <?php if ($folder !== null): ?>
    <input type="hidden" name="version" value="<?= $this->e($version) ?>">
    <?php endif ?>
    <div class="admin-card admin-form">
        <?= $this->partial('media/folder-fields', ['values' => $values, 'errors' => $errors, 'params' => $params, 'options' => $options, 'excluded' => $excluded]) ?>
    </div>
    <div class="admin-action-bar">
        <p class="admin-action-bar-status"></p>
        <div class="admin-action-bar-buttons">
            <?php if ($folder !== null): ?>
            <?= $this->partial('media/folder-delete-button', ['folder' => $folder, 'deleteBlocked' => $deleteBlocked, 'dialog' => false]) ?>
            <?php endif ?>
            <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($this->t('admin.media.folders.save')) ?></button>
        </div>
    </div>
</form>
