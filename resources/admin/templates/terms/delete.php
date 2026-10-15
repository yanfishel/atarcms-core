<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Content\Taxonomy $taxonomy */
/** @var AtarCms\Content\Term $term */
/** @var int $entries */
$routes = AtarCms\Admin\Taxonomy\TermRoutes::of(...);
$editUrl = $this->url(...$routes($taxonomy, '.edit', ['id' => $term->id]));
$this->layout('layouts/admin', [
    'title' => $this->t('admin.terms.delete_title'),
    'breadcrumbs' => [
        [$this->label($taxonomy->label), $this->url(...$routes($taxonomy, '', ['locale' => $term->locale]))],
        [$term->name, $editUrl],
        [$this->t('admin.terms.delete_title')],
    ],
    'contentLocale' => $term->locale,
]);
$pill = trim($this->partial('partials/locale-badge', ['locale' => $term->locale]));
?>
<section class="admin-card admin-card-danger admin-confirm">
    <?= $this->icon('trash-2') ?>
    <h1><?= $this->e($this->t('admin.terms.delete_title')) ?><?= $pill === '' ? '' : ' ' . $pill ?></h1>
    <p><?= $this->e($this->t($this->multilingual() ? 'admin.terms.delete_confirm' : 'admin.terms.delete_confirm_single', ['name' => $term->name, 'locale' => $this->languageName($term->locale), 'count' => $entries])) ?></p>
    <form class="admin-confirm-actions" method="post" action="<?= $this->e($this->url(...$routes($taxonomy, '.delete', ['id' => $term->id]))) ?>">
        <?= $this->csrfField() ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($editUrl) ?>"><?= $this->e($this->t('admin.terms.cancel')) ?></a>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.terms.delete_button')) ?></button>
    </form>
</section>
