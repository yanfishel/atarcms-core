<?php
/**
 * Pagination of $page: links to the named $route with $params, or built by $pageUrl (page number => URL: a section's
 * trail).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\EntryPage $page
 */
$params ??= [];
$pageUrl ??= fn(int $number): string => $this->url($route, $number > 1 ? [...$params, 'page' => $number] : $params);
?>
<?php if ($page->lastPage() > 1): ?>
<nav class="pagination" aria-label="<?= $this->e($this->t('site.pagination')) ?>">
    <?php if ($page->hasPrevious()): ?>
    <a rel="prev" href="<?= $this->e($pageUrl($page->number - 1)) ?>"><?= $this->partial('partials/icon', ['name' => 'arrow-left']) ?><?= $this->e($this->t('site.newer')) ?></a>
    <?php else: ?>
    <span></span>
    <?php endif ?>
    <span class="pagination-status"><?= $this->e($this->t('site.page_of', ['page' => $page->number, 'pages' => $page->lastPage()])) ?></span>
    <?php if ($page->hasNext()): ?>
    <a rel="next" href="<?= $this->e($pageUrl($page->number + 1)) ?>"><?= $this->e($this->t('site.older')) ?><?= $this->partial('partials/icon', ['name' => 'arrow-right']) ?></a>
    <?php else: ?>
    <span></span>
    <?php endif ?>
</nav>
<?php endif ?>
