<?php
/**
 * A library item's icon link to its page: a click on the item itself only checks it.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\Media $media
 */
$label = $this->t('admin.media.open', ['name' => $media->originalName]);
?>
<a class="admin-media-open" href="<?= $this->e($this->url('admin.media.edit', ['id' => $media->id])) ?>" title="<?= $this->e($label) ?>" aria-label="<?= $this->e($label) ?>"><?= $this->icon('pencil') ?></a>
