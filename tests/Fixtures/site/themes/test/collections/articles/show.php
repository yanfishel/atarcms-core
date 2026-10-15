<?php $this->layout('layouts/default', ['title' => $entry->title, 'description' => $entry->seo->metaDescription]); ?>
<article>
<h1><?= $this->e($entry->title) ?></h1>
<p class="date"><?= $this->e($this->date($entry->publishedAt)) ?></p>
<div class="body"><?= $entry->field('body')->html ?></div>
<?php $video = $entry->field('video'); ?>
<?php if ($video !== null): ?>
<iframe src="<?= $this->e($video->embedUrl()) ?>" title="<?= $this->e($entry->title) ?>"></iframe>
<?php endif ?>
<?php $media = $this->mediaFor($entry); ?>
<?php $cover = $media->get($entry->field('cover')); ?>
<?php if ($cover !== null): ?><div class="cover"><?= $this->img($cover, 'medium', ['class' => 'cover-image', 'alt' => null, 'title' => null]) ?></div><?php endif ?>
<?php $images = $media->list($entry->field('images')); ?>
<?php if ($images !== []): ?><ul class="gallery"><?php foreach ($images as $image): ?><li><?= $this->img($image, 'thumb') ?></li><?php endforeach ?></ul><?php endif ?>
<?php $attachment = $media->get($entry->field('attachment')); ?>
<?php if ($attachment !== null): ?><a class="attachment" href="<?= $this->e($this->mediaUrl($attachment)) ?>" download="<?= $this->e($attachment->originalName) ?>"><?= $this->e($attachment->originalName) ?></a><?php endif ?>
</article>
<ul class="topics"><?php foreach ($this->terms($entry, 'topics') as $topic): ?><li><a href="<?= $this->e($this->termUrl($topic)) ?>"><?= $this->e($topic) ?></a></li><?php endforeach ?></ul>
<ul class="tags"><?php foreach ($this->terms($entry, 'tags') as $tag): ?><li><a href="<?= $this->e($this->termUrl($tag)) ?>"><?= $this->e($tag->name) ?></a></li><?php endforeach ?></ul>
