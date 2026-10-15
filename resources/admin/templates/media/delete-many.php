<?php
/**
 * The confirmation of a bulk delete: the checked files, how many of them are used (kept), and the confirmed post.
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Media\Media> $items
 * @var int $used
 * @var array<string, string> $back the library's parameters to return to
 * @var string $backUrl
 */
$title = $this->t('admin.media.bulk.delete_title');
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$this->t('admin.media.title'), $backUrl], [$title]]]);
?>
<section class="admin-card admin-card-danger admin-confirm">
    <?= $this->icon('trash-2') ?>
    <h1><?= $this->e($title) ?></h1>
    <p><?= $this->e($this->t('admin.media.bulk.delete_confirm', ['count' => count($items)])) ?></p>
    <ul class="admin-media-usages">
        <?php foreach ($items as $item): ?>
        <li><?= $this->e($item->originalName) ?></li>
        <?php endforeach ?>
    </ul>
    <?php if ($used > 0): ?>
    <p><?= $this->e($this->t('admin.media.bulk.delete_used', ['count' => $used])) ?></p>
    <?php endif ?>
    <form class="admin-confirm-actions" method="post" action="<?= $this->e($this->url('admin.media.delete.many')) ?>">
        <?= $this->csrfField() ?>
        <input type="hidden" name="confirmed" value="1">
        <?php foreach ($items as $item): ?>
        <input type="hidden" name="ids[]" value="<?= $this->e($item->id) ?>">
        <?php endforeach ?>
        <?php foreach ($back as $name => $value): ?>
        <input type="hidden" name="back[<?= $this->e($name) ?>]" value="<?= $this->e($value) ?>">
        <?php endforeach ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($backUrl) ?>"><?= $this->e($this->t('admin.media.cancel')) ?></a>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.media.delete_button')) ?></button>
    </form>
</section>
