<?php
/**
 * The news archive: cards with the date, the summary and the tags; beside it the tags and the contacts.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Collection $collection
 * @var AtarCms\Content\EntryPage $page
 */
$title = $this->label($collection->label);
$this->layout('layouts/default', [
    'title' => $title,
    'feed' => $this->url('content.news.feed'),
    'aside' => $this->partial('partials/aside', ['tags' => true, 'contacts' => true]),
]);
$tags = $this->termsFor($page->entries, 'tags');
$media = $this->mediaFor($page->entries);
?>
<?= $this->partial('partials/breadcrumbs', ['items' => [[$title, null]]]) ?>
<header class="page-header">
    <h1><?= $this->e($title) ?></h1>
</header>
<?php if ($page->entries === []): ?>
<p><?= $this->e($this->t('site.no_entries')) ?></p>
<?php else: ?>
<ul class="cards">
    <?php foreach ($page->entries as $item): ?>
    <li><?= $this->partial('partials/card', ['item' => $item, 'url' => $this->entryUrl($item), 'media' => $media, 'date' => true, 'tags' => $tags[$item->id] ?? [], 'heading' => 'h2']) ?></li>
    <?php endforeach ?>
</ul>
<?php endif ?>
<?= $this->partial('partials/pagination', ['page' => $page, 'route' => 'content.news.index']) ?>
