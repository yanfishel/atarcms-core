<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Content\Collection $collection */
/** @var AtarCms\Content\Entry $entry */
/** @var bool $canEdit */
$listUrl = $this->url('admin.entries', ['collection' => $collection->name, 'locale' => $entry->locale]);
$editUrl = $this->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $entry->id]);
$this->layout('layouts/admin', [
    'title' => $this->t('admin.entries.delete_title'),
    'breadcrumbs' => [
        [$this->label($collection->label), $collection->single ? null : $listUrl],
        [$entry->title, $canEdit ? $editUrl : null],
        [$this->t('admin.entries.delete_title')],
    ],
    // The entry's language, for the top bar's switch (none for a collection in one language).
    ...($collection->translatable ? ['contentLocale' => $entry->locale] : []),
]);
$pill = $collection->translatable ? trim($this->partial('partials/locale-badge', ['locale' => $entry->locale])) : '';
?>
<section class="admin-card admin-card-danger admin-confirm">
    <?= $this->icon('trash-2') ?>
    <h1><?= $this->e($this->t('admin.entries.delete_title')) ?><?= $pill === '' ? '' : ' ' . $pill ?></h1>
    <p><?= $this->e($this->t($this->multilingual() ? 'admin.entries.delete_confirm' : 'admin.entries.delete_confirm_single', ['title' => $entry->title, 'locale' => $this->languageName($entry->locale)])) ?></p>
    <form class="admin-confirm-actions" method="post" action="<?= $this->e($this->url('admin.entries.delete', ['collection' => $collection->name, 'id' => $entry->id])) ?>">
        <?= $this->csrfField() ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($canEdit ? $editUrl : $listUrl) ?>"><?= $this->e($this->t('admin.entries.cancel')) ?></a>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.entries.delete_button')) ?></button>
    </form>
</section>
