<?php
/**
 * The text and files of an article, news item or page ($entry), as on its own page and inline on a section page: the
 * cover, the video, the text, the gallery and the attachment. $media holds the page's files; $first is true for the
 * page's main picture (loaded at once).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Entry $entry
 * @var AtarCms\Media\MediaSet $media
 * @var bool $first
 */
$collection = $entry->collection;
$cover = $collection->field('cover') !== null ? $media->get($entry->field('cover')) : null;
$video = $collection->field('video') !== null ? $entry->field('video') : null;
$images = $collection->field('images') !== null ? $media->list($entry->field('images')) : [];
$attachment = $collection->field('attachment') !== null ? $media->get($entry->field('attachment')) : null;
$eager = ($first ?? false) ? ['loading' => 'eager', 'fetchpriority' => 'high'] : [];
?>
<?php if ($cover !== null): ?>
<figure class="entry-cover"><div class="frame"><?= $this->img($cover, 'large', ['sizes' => '(max-width: 64rem) 100vw, 50rem', ...$eager]) ?></div></figure>
<?php endif ?>
<?php if ($video !== null): ?>
<iframe class="video" src="<?= $this->e($video->embedUrl()) ?>" title="<?= $this->e($entry->title) ?>" allowfullscreen loading="lazy"></iframe>
<?php endif ?>
<?php if ($collection->field('body') !== null): ?>
<div class="rich-text"><?= $entry->field('body')->html ?></div>
<?php endif ?>
<?php if ($images !== []): ?>
<section class="gallery-block" aria-label="<?= $this->e($this->t('site.gallery')) ?>" data-lightbox-labels data-close="<?= $this->e($this->t('site.lightbox.close')) ?>" data-zoom="<?= $this->e($this->t('site.lightbox.zoom')) ?>" data-previous="<?= $this->e($this->t('site.lightbox.previous')) ?>" data-next="<?= $this->e($this->t('site.lightbox.next')) ?>" data-error="<?= $this->e($this->t('site.lightbox.error')) ?>">
    <ul class="gallery">
        <?php foreach ($images as $image): ?>
        <?php // The size of the "large" variant (theme.php: 1600 wide, never enlarged), for the lightbox (gallery.js). ?>
        <?php $scale = $image->width !== null && $image->width > 1600 ? 1600 / $image->width : 1.0; ?>
        <li><a href="<?= $this->e($this->mediaUrl($image, 'large')) ?>"<?php if ($image->width !== null && $image->height !== null): ?> data-pswp-width="<?= $this->e((int) round($image->width * $scale)) ?>" data-pswp-height="<?= $this->e((int) round($image->height * $scale)) ?>"<?php endif ?>><span class="frame"><?= $this->img($image, 'thumb', ['sizes' => '(max-width: 40rem) 50vw, 15rem']) ?></span></a></li>
        <?php endforeach ?>
    </ul>
</section>
<?php endif ?>
<?php if ($attachment !== null): ?>
<p class="attachment"><a href="<?= $this->e($this->mediaUrl($attachment)) ?>" download="<?= $this->e($attachment->originalName) ?>"><?= $this->e($this->t('site.download')) ?>: <?= $this->e($attachment->originalName) ?></a></p>
<?php endif ?>
