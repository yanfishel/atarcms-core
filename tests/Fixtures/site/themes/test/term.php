<?php $this->layout('layouts/default', ['title' => $term->name]); ?>
<h1 class="term" data-taxonomy="<?= $this->e($taxonomy->name) ?>"><?= $this->e($term->name) ?></h1>
<ul class="term-entries"><?php foreach ($page->entries as $item): ?><li class="term-entry"><a href="<?= $this->e($this->entryUrl($item)) ?>"><?= $this->e($item->title) ?></a></li><?php endforeach ?></ul>
<p class="pages"><?= $this->e($page->number) ?>/<?= $this->e($page->lastPage()) ?></p>
