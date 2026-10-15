<?php
/**
 * The site search: a GET form to /search in the page's language.
 *
 * @var AtarCms\View\Template $this
 * @var string $query
 */
?>
<form class="search-form" action="<?= $this->e($this->url('search')) ?>" method="get" role="search">
    <input type="search" name="q" value="<?= $this->e($query) ?>" maxlength="200" aria-label="<?= $this->e($this->t('site.search.label')) ?>" placeholder="<?= $this->e($this->t('site.search.label')) ?>">
    <button type="submit"><?= $this->e($this->t('site.search.submit')) ?></button>
</form>
