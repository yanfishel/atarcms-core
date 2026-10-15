<?php $this->layout('layouts/default', ['title' => $collection->label]); ?>
<ul class="entries"><?php foreach ($page->entries as $entry): ?><li><a href="<?= $this->e($this->entryUrl($entry)) ?>"><?= $this->e($entry->title) ?></a></li><?php endforeach ?></ul>
<p class="pages"><?= $this->e($page->number) ?>/<?= $this->e($page->lastPage()) ?></p>
<?php if ($page->hasNext()): ?>
<a rel="next" href="<?= $this->e($this->url('content.articles.index', ['page' => $page->number + 1])) ?>">Next</a>
<?php endif ?>
<?php $contact = $this->single('contact'); ?>
<?php if ($contact !== null): ?>
<p class="phone"><?= $this->e($contact->field('phone')) ?></p>
<?php endif ?>
<?php $settings = $this->single('settings'); ?><?php if ($settings !== null): ?><p class="tagline"><?= $this->e($settings->field('tagline')) ?></p><?php endif ?>
<p class="latest"><?php foreach ($this->entries('articles')->latest(1) as $latest): ?><?= $this->e($latest->title) ?><?php endforeach ?></p>
<a class="in-ru" href="<?= $this->e($this->url('content.articles.index', [], 'ru')) ?>">RU</a>
<?php $tags = $this->termsFor($page->entries, 'tags'); ?>
<p class="entry-tags"><?php foreach ($page->entries as $item): ?><?php foreach ($tags[$item->id] ?? [] as $tag): ?><span data-entry="<?= $this->e($item->slug) ?>"><?= $this->e($tag) ?></span><?php endforeach ?><?php endforeach ?></p>
<?php $covers = $this->mediaFor($page->entries); ?>
<p class="covers"><?php foreach ($page->entries as $item): ?><?= $this->img($covers->get($item->field('cover')), 'thumb') ?><?php endforeach ?></p>
