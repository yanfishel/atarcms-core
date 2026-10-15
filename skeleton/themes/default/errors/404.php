<?php
/**
 * Page not found: the code, a sentence, the search and the way home.
 *
 * @var AtarCms\View\Template $this
 */
$this->layout('layouts/default', ['title' => $this->t('site.not_found')]);
?>
<section class="error-page">
    <p class="error-code" aria-hidden="true">404</p>
    <h1><?= $this->e($this->t('site.not_found')) ?></h1>
    <p><?= $this->e($this->t('site.not_found_text')) ?></p>
    <?= $this->partial('partials/search-form', ['query' => '']) ?>
    <p><a class="button" href="<?= $this->e($this->url('content.home.show')) ?>"><?= $this->e($this->t('site.back_home')) ?></a></p>
</section>
