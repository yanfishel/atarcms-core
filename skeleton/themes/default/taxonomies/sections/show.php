<?php
/**
 * A section: its articles, newest first. An article with a summary is announced (picture, title, summary); one
 * without is shown here in full, so a subsection holding one such article reads as a plain page. Beside it the
 * subsections of its top section and the contacts. $seo: the section's own title and description, else its parents'.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @var AtarCms\Content\Term $term
 * @var AtarCms\Content\EntryPage $page
 * @var AtarCms\Content\PageSeo $seo
 */
$this->layout('layouts/default', [
    'title' => $seo->title,
    'description' => $seo->description,
    'aside' => $this->partial('partials/aside', ['section' => $term, 'news' => true, 'contacts' => true]),
]);
$trail = $this->termTrail($term);
$urls = $this->entryUrls($page->entries);
$media = $this->mediaFor($page->entries);
?>
<?= $this->partial('partials/breadcrumbs', ['trail' => $trail]) ?>
<header class="page-header">
    <h1><?= $this->e($term->name) ?></h1>
</header>
<?php if ($page->entries === []): ?>
<p><?= $this->e($this->t('site.no_entries')) ?></p>
<?php endif ?>
<div class="list">
    <?php foreach ($page->entries as $index => $item): ?>
    <?php if ($item->field('summary')->isEmpty()): ?>
    <article class="article-inline">
        <?php if ($item->title !== $term->name): ?>
        <h2><?= $this->e($item->title) ?></h2>
        <?php endif ?>
        <?= $this->partial('partials/article-body', ['entry' => $item, 'media' => $media, 'first' => $index === 0]) ?>
    </article>
    <?php else: ?>
    <?php $url = $urls[$item->id] ?? '#'; ?>
    <?php $cover = $media->get($item->field('cover')); ?>
    <article class="list-item">
        <?php if ($cover !== null): ?>
        <div class="frame"><?= $this->img($cover, 'card', ['sizes' => '(max-width: 40rem) 100vw, 15rem', 'alt' => '']) ?></div>
        <?php endif ?>
        <div>
            <h2><a href="<?= $this->e($url) ?>"><?= $this->e($item->title) ?></a></h2>
            <div class="card-summary rich-text"><?= $item->field('summary')->html ?></div>
            <p class="read-more" aria-hidden="true"><?= $this->e($this->t('site.read_more')) ?></p>
        </div>
    </article>
    <?php endif ?>
    <?php endforeach ?>
</div>
<?= $this->partial('partials/pagination', ['page' => $page, 'pageUrl' => fn(int $number): string => (string) $this->termUrl($term, $number)]) ?>
