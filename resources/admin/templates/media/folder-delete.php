<?php
/**
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\MediaFolder $folder
 * @var list<AtarCms\Media\MediaFolder> $trail
 */
$back = $this->url('admin.media.folders.edit', ['id' => $folder->id]);
$breadcrumbs = [[$this->t('admin.media.title'), $this->url('admin.media')]];
foreach ($trail as $step) {
    $breadcrumbs[] = [$step->name, $this->url('admin.media', ['folder' => $step->id])];
}
$breadcrumbs[] = [$this->t('admin.media.folders.delete_title')];
$this->layout('layouts/admin', ['title' => $this->t('admin.media.folders.delete_title'), 'breadcrumbs' => $breadcrumbs]);
?>
<section class="admin-card admin-card-danger admin-confirm">
    <?= $this->icon('trash-2') ?>
    <h1><?= $this->e($this->t('admin.media.folders.delete_title')) ?></h1>
    <p><?= $this->e($this->t('admin.media.folders.delete_confirm', ['name' => $folder->name])) ?></p>
    <form class="admin-confirm-actions" method="post" action="<?= $this->e($this->url('admin.media.folders.delete', ['id' => $folder->id])) ?>">
        <?= $this->csrfField() ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($back) ?>"><?= $this->e($this->t('admin.media.cancel')) ?></a>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.media.folders.delete_button')) ?></button>
    </form>
</section>
