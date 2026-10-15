<?php
/**
 * A term page (a section, a tag) of a taxonomy without its own taxonomies/{name}/show.php: its entries, paginated.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @var AtarCms\Content\Term $term
 * @var AtarCms\Content\EntryPage $page
 * @var AtarCms\Content\PageSeo $seo
 */
$this->layout('layouts/default', ['title' => $seo->title, 'description' => $seo->description]);
$media = $this->mediaFor($page->entries);
$urls = $this->entryUrls($page->entries);
$trail = $this->termTrail($term);
?>
<?php if (count($trail) > 1): ?>
<nav class="breadcrumbs" aria-label="<?= $this->e($this->t('site.breadcrumbs')) ?>">
    <ol>
        <?php foreach ($trail as $step): ?>
        <li><?php if ($step->id === $term->id): ?><span aria-current="page"><?= $this->e($step->name) ?></span><?php else: ?><a href="<?= $this->e((string) $this->termUrl($step)) ?>"><?= $this->e($step->name) ?></a><?php endif ?></li>
        <?php endforeach ?>
    </ol>
</nav>
<?php endif ?>
<h1><?= $this->e($term->name) ?></h1>
<?php if ($page->entries === []): ?>
<p><?= $this->e($this->t('site.no_entries')) ?></p>
<?php else: ?>
<ul class="cards">
    <?php foreach ($page->entries as $item): ?>
    <li><?= $this->partial('partials/card', ['item' => $item, 'url' => $urls[$item->id] ?? '#', 'media' => $media]) ?></li>
    <?php endforeach ?>
</ul>
<?php endif ?>
<?= $this->partial('partials/pagination', ['page' => $page, 'pageUrl' => fn(int $number): string => (string) $this->termUrl($term, $number)]) ?>
