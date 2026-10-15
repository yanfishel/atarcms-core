<?php
/**
 * Page not found.
 *
 * @var AtarCms\View\Template $this
 */
$this->layout('layouts/default', ['title' => $this->t('site.not_found')]);
?>
<h1><?= $this->e($this->t('site.not_found')) ?></h1>
<p><?= $this->e($this->t('site.not_found_text')) ?></p>
<?= $this->partial('partials/search-form', ['query' => '']) ?>
