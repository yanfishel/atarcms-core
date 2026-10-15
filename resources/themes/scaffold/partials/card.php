<?php
/**
 * An entry in a list: its picture (the first image field, when the collection has one), title, date and summary.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Entry $item
 * @var string $url
 * @var AtarCms\Media\MediaSet $media $this->mediaFor() of the whole list
 */
$cover = null;
foreach ($item->collection->fields as $name => $field) {
    if ($field->type->name() === 'image') {
        $cover = $media->get($item->field($name));
        break;
    }
}
$summary = $item->collection->field('summary') !== null ? $item->field('summary') : null;
?>
<article class="card">
    <?php if ($cover !== null): ?>
    <?= $this->img($cover, 'card', ['alt' => '', 'sizes' => '(max-width: 40rem) 100vw, 20rem']) ?>
    <?php endif ?>
    <h2><a href="<?= $this->e($url) ?>"><?= $this->e($item->title) ?></a></h2>
    <?php if ($item->publishedAt !== null && !$item->collection->single): ?>
    <p class="meta"><time datetime="<?= $this->e($this->date($item->publishedAt, 'Y-m-d')) ?>"><?= $this->e($this->readableDate($item->publishedAt, false)) ?></time></p>
    <?php endif ?>
    <?php if (is_string($summary) && $summary !== ''): ?>
    <p><?= $this->e($summary) ?></p>
    <?php endif ?>
</article>
