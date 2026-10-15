<?php
/**
 * An entry of a collection without its own template (also a single() page before its entry exists).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Entry|null $entry
 * @var AtarCms\Content\Collection $collection
 * @var AtarCms\Content\PageSeo|null $seo
 */
$heading = $entry?->title ?? $this->label($collection->label);
$this->layout('layouts/default', ['title' => $seo?->title ?? $heading, 'description' => $seo?->description]);
?>
<?= $this->partial('partials/breadcrumbs', ['items' => [[$heading, null]]]) ?>
<article class="article">
    <header class="page-header">
        <h1><?= $this->e($heading) ?></h1>
    </header>
    <?php if ($entry !== null): ?>
    <?= $this->partial('partials/article-body', ['entry' => $entry, 'media' => $this->mediaFor($entry), 'first' => true]) ?>
    <?php endif ?>
</article>
