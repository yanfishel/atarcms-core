<?php
/** @var AtarCms\View\Template $this */
/** @var string $location */
/** @var AtarCms\Content\Menu\MenuItem $item */
/** @var string $label */
/** @var int $subitems */
$editUrl = $this->url('admin.menus.edit', ['location' => $location, 'id' => $item->id]);
$this->layout('layouts/admin', [
    'title' => $this->t('admin.menus.delete_title'),
    'breadcrumbs' => [
        [$this->t('admin.menus.title'), $this->url('admin.menus.show', ['location' => $location, 'locale' => $item->locale])],
        [$label, $editUrl],
        [$this->t('admin.menus.delete_title')],
    ],
    'contentLocale' => $item->locale,
]);
$pill = trim($this->partial('partials/locale-badge', ['locale' => $item->locale]));
?>
<section class="admin-card admin-card-danger admin-confirm">
    <?= $this->icon('trash-2') ?>
    <h1><?= $this->e($this->t('admin.menus.delete_title')) ?><?= $pill === '' ? '' : ' ' . $pill ?></h1>
    <p><?= $this->e($this->t($this->multilingual() ? 'admin.menus.delete_confirm' : 'admin.menus.delete_confirm_single', ['label' => $label, 'locale' => $this->languageName($item->locale), 'count' => $subitems])) ?></p>
    <form class="admin-confirm-actions" method="post" action="<?= $this->e($this->url('admin.menus.delete', ['location' => $location, 'id' => $item->id])) ?>">
        <?= $this->csrfField() ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($editUrl) ?>"><?= $this->e($this->t('admin.menus.cancel')) ?></a>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.menus.delete_button')) ?></button>
    </form>
</section>
