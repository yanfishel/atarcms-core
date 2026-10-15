<?php
/**
 * An entry as a card (home blocks, lists, related news): its picture with the leaf corner, title, date and summary;
 * the whole card is one link.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Entry $item
 * @var string $url
 * @var AtarCms\Media\MediaSet $media the list's files (mediaFor())
 * @var bool $date whether to show the publication date
 * @var list<AtarCms\Content\Term> $tags tags to show under the card (linked above the card's link)
 * @var string $heading the heading element: h2 or h3
 */
$cover = $item->collection->field('cover') !== null ? $media->get($item->field('cover')) : null;
$summary = $item->collection->field('summary') !== null ? $item->field('summary') : null;
$heading ??= 'h3';
?>
<article class="card">
    <?php if ($cover !== null): ?>
    <div class="frame"><?= $this->img($cover, 'card', ['sizes' => '(max-width: 40rem) 100vw, 24rem', 'alt' => '']) ?></div>
    <?php endif ?>
    <<?= $heading ?>><a href="<?= $this->e($url) ?>"><?= $this->e($item->title) ?></a></<?= $heading ?>>
    <?php if (($date ?? false) && $item->publishedAt !== null): ?>
    <p class="card-date"><time datetime="<?= $this->e($this->date($item->publishedAt, 'Y-m-d')) ?>"><?= $this->e($this->readableDate($item->publishedAt, false)) ?></time></p>
    <?php endif ?>
    <?php if ($summary !== null && !$summary->isEmpty()): ?>
    <div class="card-summary rich-text"><?= $summary->html ?></div>
    <?php endif ?>
    <?php if (($tags ?? []) !== []): ?>
    <ul class="tags">
        <?php foreach ($tags as $tag): ?>
        <li><a class="tag" href="<?= $this->e($this->termUrl($tag)) ?>">#<?= $this->e($tag->name) ?></a></li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</article>
