<?php
/**
 * The folder form's red Delete (users with "media.delete"): a link to the confirmation (in the dialog when the form is
 * one), or a locked button when the folder holds subfolders or files: aria-disabled (it stays in the Tab order and does
 * nothing), its reason its title (a tip on keyboard focus) and its description.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\MediaFolder $folder
 * @var array{key: string, params: array<string, int>}|null $deleteBlocked
 * @var bool $dialog
 */
?>
<?php if ($this->can('media.delete')): ?>
<?php if ($deleteBlocked !== null): ?>
<?php $reason = $this->t($deleteBlocked['key'], $deleteBlocked['params']); ?>
<button type="button" class="admin-button admin-button-link admin-button-danger admin-locked" aria-disabled="true" title="<?= $this->e($reason) ?>" aria-describedby="folder-delete-reason"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.media.folders.delete')) ?></button><span class="admin-visually-hidden" id="folder-delete-reason"><?= $this->e($reason) ?></span>
<?php else: ?>
<a class="admin-button admin-button-link admin-button-danger" href="<?= $this->e($this->url('admin.media.folders.delete.confirm', ['id' => $folder->id])) ?>"<?= $dialog ? ' data-dialog-open' : '' ?>><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.media.folders.delete')) ?></a>
<?php endif ?>
<?php endif ?>
