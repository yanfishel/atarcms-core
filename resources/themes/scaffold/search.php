<?php
/**
 * The search page: the form, the number of results and the results.
 *
 * @var AtarCms\View\Template $this
 * @var string $query
 * @var list<AtarCms\Search\SearchResult> $results
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var bool $tooShort
 */
$this->layout('layouts/default', ['title' => $this->t('site.search.title'), 'robots' => 'noindex']);
?>
<h1><?= $this->e($this->t('site.search.title')) ?></h1>
<?= $this->partial('partials/search-form', ['query' => $query]) ?>
<?php if ($query !== '' && $tooShort): ?>
<p><?= $this->e($this->t('site.search.too_short')) ?></p>
<?php elseif ($query !== ''): ?>
<p role="status"><?= $this->e($this->t('site.search.found', ['count' => $total])) ?></p>
<ol class="results">
    <?php foreach ($results as $result): ?>
    <li>
        <h2><a href="<?= $this->e($result->url) ?>"><?= $this->e($result->entry->title) ?></a></h2>
        <?php if ($result->description !== ''): ?>
        <p><?= $this->e($result->description) ?></p>
        <?php endif ?>
    </li>
    <?php endforeach ?>
</ol>
<?php if ($pages > 1): ?>
<nav class="pagination" aria-label="<?= $this->e($this->t('site.pagination')) ?>">
    <?php if ($page > 1): ?>
    <a rel="prev" href="<?= $this->e($this->url('search', $page > 2 ? ['q' => $query, 'page' => $page - 1] : ['q' => $query])) ?>"><?= $this->e($this->t('site.newer')) ?></a>
    <?php endif ?>
    <span><?= $this->e($this->t('site.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
    <?php if ($page < $pages): ?>
    <a rel="next" href="<?= $this->e($this->url('search', ['q' => $query, 'page' => $page + 1])) ?>"><?= $this->e($this->t('site.older')) ?></a>
    <?php endif ?>
</nav>
<?php endif ?>
<?php endif ?>
