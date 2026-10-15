<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Media\Media $media */
?>
<?php if ($media->isImage()): ?>
<img class="admin-media-thumb" src="<?= $this->e($this->mediaThumb($media)) ?>" alt="" loading="lazy" width="160" height="160">
<?php else: ?>
<span class="admin-media-thumb admin-media-file"><?= $this->e(strtoupper($media->extension())) ?></span>
<?php endif ?>
