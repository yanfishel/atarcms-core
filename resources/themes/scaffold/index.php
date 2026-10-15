<?php
/**
 * The archive of a collection without its own collections/{name}/index.php: its entries, newest first, paginated.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Collection $collection
 * @var AtarCms\Content\EntryPage $page
 */
$heading = $this->label($collection->label);
$this->layout('layouts/default', ['title' => $heading]);
$media = $this->mediaFor($page->entries);
$urls = $this->entryUrls($page->entries);
?>
<h1><?= $this->e($heading) ?></h1>
<?php if ($page->entries === []): ?>
<p><?= $this->e($this->t('site.no_entries')) ?></p>
<?php else: ?>
<ul class="cards">
    <?php foreach ($page->entries as $item): ?>
    <li><?= $this->partial('partials/card', ['item' => $item, 'url' => $urls[$item->id] ?? '#', 'media' => $media]) ?></li>
    <?php endforeach ?>
</ul>
<?php endif ?>
<?= $this->partial('partials/pagination', ['page' => $page, 'pageUrl' => fn(int $number): string => $this->url('content.' . $collection->name . '.index', $number > 1 ? ['page' => $number] : [])]) ?>
