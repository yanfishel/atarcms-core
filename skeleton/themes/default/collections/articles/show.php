<?php
/**
 * An article: the breadcrumbs of its section, the text and files; beside it the subsections of its top section, more
 * articles of its section and the contacts. $seo: the article's own title and description, else its section's (a title
 * with {title}), then the parents'.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Entry $entry
 * @var AtarCms\Content\PageSeo $seo
 */
$section = $this->terms($entry, 'sections')[0] ?? null;
$this->layout('layouts/default', [
    'title' => $seo->title,
    'description' => $seo->description,
    'aside' => $this->partial('partials/aside', ['section' => $section, 'more' => $section, 'exclude' => $entry->id, 'contacts' => true]),
]);
?>
<?= $this->partial('partials/breadcrumbs', ['trail' => $section !== null ? $this->termTrail($section) : [], 'items' => [[$entry->title, null]]]) ?>
<article class="article">
    <header class="page-header">
        <h1><?= $this->e($entry->title) ?></h1>
    </header>
    <?= $this->partial('partials/article-body', ['entry' => $entry, 'media' => $this->mediaFor($entry), 'first' => true]) ?>
</article>
