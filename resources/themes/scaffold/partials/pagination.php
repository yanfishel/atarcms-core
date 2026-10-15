<?php
/**
 * Pagination of $page; $pageUrl builds the URL of a page number.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\EntryPage $page
 * @var Closure(int): string $pageUrl
 */
?>
<?php if ($page->lastPage() > 1): ?>
<nav class="pagination" aria-label="<?= $this->e($this->t('site.pagination')) ?>">
    <?php if ($page->hasPrevious()): ?>
    <a rel="prev" href="<?= $this->e($pageUrl($page->number - 1)) ?>"><?= $this->e($this->t('site.newer')) ?></a>
    <?php endif ?>
    <span><?= $this->e($this->t('site.page_of', ['page' => $page->number, 'pages' => $page->lastPage()])) ?></span>
    <?php if ($page->hasNext()): ?>
    <a rel="next" href="<?= $this->e($pageUrl($page->number + 1)) ?>"><?= $this->e($this->t('site.older')) ?></a>
    <?php endif ?>
</nav>
<?php endif ?>
