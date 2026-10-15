<?php $this->layout('layouts/default', ['title' => $seo->title, 'description' => $seo->description]); ?>
<nav class="trail"><?php foreach ($this->termTrail($term) as $crumb): ?><a href="<?= $this->e($this->termUrl($crumb)) ?>"><?= $this->e($crumb->name) ?></a> / <?php endforeach ?></nav>
<h1 class="term" data-taxonomy="<?= $this->e($taxonomy->name) ?>"><?= $this->e($term->name) ?></h1>
<?php $urls = $this->entryUrls($page->entries); ?>
<?php foreach ($page->entries as $item): ?>
<?php if ($item->field('summary')->isEmpty()): ?>
<article class="full"><h2><?= $this->e($item->title) ?></h2><div class="body"><?= $item->field('body')->html ?></div></article>
<?php else: ?>
<article class="announce"><h2><a href="<?= $this->e($urls[$item->id] ?? '') ?>"><?= $this->e($item->title) ?></a></h2><div class="summary"><?= $item->field('summary')->html ?></div></article>
<?php endif ?>
<?php endforeach ?>
<p class="pages"><?= $this->e($page->number) ?>/<?= $this->e($page->lastPage()) ?></p>
