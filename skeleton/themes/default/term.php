<?php
/**
 * A term page of a taxonomy without its own template (the tags): the entries carrying the term as cards; beside them
 * all tags.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @var AtarCms\Content\Term $term
 * @var AtarCms\Content\EntryPage $page
 */
$taxonomyLabel = $this->label($taxonomy->label);
$this->layout('layouts/default', [
    'title' => $term->name . ' — ' . $taxonomyLabel,
    'aside' => $this->partial('partials/aside', ['tags' => $taxonomy->name === 'tags', 'news' => true]),
]);
$media = $this->mediaFor($page->entries);
$urls = $this->entryUrls($page->entries);
?>
<?= $this->partial('partials/breadcrumbs', ['items' => [[$taxonomyLabel, null], ['#' . $term->name, null]]]) ?>
<header class="page-header">
    <p class="page-kicker"><?= $this->e($taxonomyLabel) ?></p>
    <h1>#<?= $this->e($term->name) ?></h1>
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
<?= $this->partial('partials/pagination', ['page' => $page, 'pageUrl' => fn(int $number): string => (string) $this->termUrl($term, $number)]) ?>
