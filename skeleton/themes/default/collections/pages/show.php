<?php
/**
 * A page: its text and files; beside it the latest news and the contacts.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Entry $entry
 * @var AtarCms\Content\PageSeo $seo
 */
$this->layout('layouts/default', [
    'title' => $seo->title,
    'description' => $seo->description,
    'aside' => $this->partial('partials/aside', ['news' => true, 'contacts' => true]),
]);
?>
<?= $this->partial('partials/breadcrumbs', ['items' => [[$entry->title, null]]]) ?>
<article class="article">
    <header class="page-header">
        <h1><?= $this->e($entry->title) ?></h1>
    </header>
    <?= $this->partial('partials/article-body', ['entry' => $entry, 'media' => $this->mediaFor($entry), 'first' => true]) ?>
</article>
