<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Media\Media $media */
/** @var list<array{title: string|null, collection: string|null, locale: string|null, field: string|null, url: string|null}> $usages */
/** @var array{url: string|null, uses: list<string>}|null $settingsUse */
/** @var string $back where Cancel leads: the file's page, or the dashboard without the right to open it */
/** @var bool $canEdit the user may open the file's page and the library ("media.upload") */
$this->layout('layouts/admin', [
    'title' => $this->t('admin.media.delete_title'),
    'breadcrumbs' => [
        [$this->t('admin.media.title'), $canEdit ? $this->url('admin.media') : null],
        [$media->originalName, $canEdit ? $this->url('admin.media.edit', ['id' => $media->id]) : null],
        [$this->t('admin.media.delete_title')],
    ],
]);
?>
<section class="admin-card admin-card-danger admin-confirm">
    <?= $this->icon('trash-2') ?>
    <h1><?= $this->e($this->t('admin.media.delete_title')) ?></h1>
    <?php if ($usages !== [] || $settingsUse !== null): ?>
    <p><?= $this->e($this->t('admin.media.delete_blocked')) ?></p>
    <?= $this->partial('media/usages', ['usages' => $usages, 'settings' => $settingsUse]) ?>
    <div class="admin-confirm-actions"><a class="admin-button admin-button-secondary" href="<?= $this->e($back) ?>"><?= $this->e($this->t('admin.media.cancel')) ?></a></div>
    <?php else: ?>
    <p><?= $this->e($this->t('admin.media.delete_confirm', ['name' => $media->originalName])) ?></p>
    <form class="admin-confirm-actions" method="post" action="<?= $this->e($this->url('admin.media.delete', ['id' => $media->id])) ?>">
        <?= $this->csrfField() ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($back) ?>"><?= $this->e($this->t('admin.media.cancel')) ?></a>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.media.delete_button')) ?></button>
    </form>
    <?php endif ?>
</section>
