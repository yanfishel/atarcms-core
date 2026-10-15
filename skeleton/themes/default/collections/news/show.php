<?php
/**
 * A news item: the date, the text and files, its tags and three more news; beside it the tags and the contacts.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Entry $entry
 * @var AtarCms\Content\Collection $collection
 * @var AtarCms\Content\PageSeo $seo
 */
$this->layout('layouts/default', [
    'title' => $seo->title,
    'description' => $seo->description,
    'feed' => $this->url('content.news.feed'),
    'aside' => $this->partial('partials/aside', ['tags' => true, 'contacts' => true]),
]);
$tags = $this->terms($entry, 'tags');
$related = array_slice(array_values(array_filter($this->entries('news')->latest(4), static fn(AtarCms\Content\Entry $item): bool => $item->id !== $entry->id)), 0, 3);
$relatedMedia = $this->mediaFor($related);
?>
<?= $this->partial('partials/breadcrumbs', ['items' => [[$this->label($collection->label), $this->url('content.news.index')], [$entry->title, null]]]) ?>
<article class="news">
    <header class="page-header">
        <h1><?= $this->e($entry->title) ?></h1>
        <?php if ($entry->publishedAt !== null): ?>
        <p class="page-meta"><?= $this->partial('partials/icon', ['name' => 'calendar']) ?><time datetime="<?= $this->e($this->date($entry->publishedAt, 'Y-m-d')) ?>"><?= $this->e($this->readableDate($entry->publishedAt, false)) ?></time></p>
        <?php endif ?>
    </header>
    <?= $this->partial('partials/article-body', ['entry' => $entry, 'media' => $this->mediaFor($entry), 'first' => true]) ?>
    <?php if ($tags !== []): ?>
    <div class="entry-tags">
        <h2><?= $this->e($this->t('site.tags')) ?></h2>
        <ul class="tags">
            <?php foreach ($tags as $tag): ?>
            <li><a class="tag" href="<?= $this->e($this->termUrl($tag)) ?>">#<?= $this->e($tag->name) ?></a></li>
            <?php endforeach ?>
        </ul>
    </div>
    <?php endif ?>
</article>
<?php if ($related !== []): ?>
<section class="related" aria-labelledby="related-title">
    <h2 id="related-title"><?= $this->e($this->t('site.related')) ?></h2>
    <ul class="cards">
        <?php foreach ($related as $item): ?>
        <li><?= $this->partial('partials/card', ['item' => $item, 'url' => $this->entryUrl($item), 'media' => $relatedMedia, 'date' => true]) ?></li>
        <?php endforeach ?>
    </ul>
</section>
<?php endif ?>
