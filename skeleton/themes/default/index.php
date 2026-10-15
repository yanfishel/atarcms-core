<?php
/**
 * The archive of a collection without its own index template: cards, newest first.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Collection $collection
 * @var AtarCms\Content\EntryPage $page
 */
$title = $this->label($collection->label);
$this->layout('layouts/default', ['title' => $title]);
$media = $this->mediaFor($page->entries);
$urls = $this->entryUrls($page->entries);
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
    <li><?= $this->partial('partials/card', ['item' => $item, 'url' => $urls[$item->id] ?? '#', 'media' => $media, 'date' => true, 'heading' => 'h2']) ?></li>
    <?php endforeach ?>
</ul>
<?php endif ?>
<?= $this->partial('partials/pagination', ['page' => $page, 'route' => 'content.' . $collection->name . '.index']) ?>
