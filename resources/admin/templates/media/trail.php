<?php
/**
 * The path of the folder shown, "All files › A › B"; the last step is the current folder.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\MediaFolderTree $tree
 * @var int|null $current
 * @var callable(int|null): string $url a folder => its URL
 * @var string|null $target (optional) the hx-target of the links
 */
$target ??= null;
$steps = [[null, $this->t('admin.media.folders.root')]];
foreach ($tree->trail($current) as $folder) {
    $steps[] = [$folder->id, $folder->name];
}
$last = count($steps) - 1;
$sep = '<span class="admin-media-trail-sep">' . $this->icon('chevron-right') . '</span>';
?>
<nav class="admin-media-trail" aria-label="<?= $this->e($this->t('admin.media.folders.trail')) ?>">
    <ol>
        <?php foreach ($steps as $index => [$id, $name]): ?>
        <?php if ($index === $last): ?>
        <li><?= $index > 0 ? $sep : '' ?><span aria-current="page"><?= $this->e($name) ?></span></li>
        <?php else: ?>
        <?php $href = $url($id) ?>
        <li><?= $index > 0 ? $sep : '' ?><a href="<?= $this->e($href) ?>"<?php if ($target !== null): ?> hx-get="<?= $this->e($href) ?>" hx-target="<?= $this->e($target) ?>" hx-swap="outerHTML"<?php endif ?>><?= $this->e($name) ?></a></li>
        <?php endif ?>
        <?php endforeach ?>
    </ol>
</nav>
