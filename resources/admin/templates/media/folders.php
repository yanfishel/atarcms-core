<?php
/**
 * The folder tree of the library and the picker: "All files" (the root) and every folder, fully open, the current one
 * marked, the levels joined by guide lines. With $target the links load through htmx into that element (the picker)
 * and still work as plain links. With $editable (the library) each folder row has a pencil, shown on hover and on
 * focus, that opens the folder's form in the page's dialog.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\MediaFolderTree $tree
 * @var int|null $current the folder shown (null: the root)
 * @var callable(int|null): string $url a folder => its URL
 * @var string|null $target (optional) the hx-target of the links
 * @var bool $editable (optional) rows get the pencil of the folder's form
 */
$target ??= null;
$editable ??= false;
$link = function (?int $folder, string $name) use ($url, $target, $current): string {
    $href = $this->e($url($folder));
    $hx = $target === null ? '' : ' hx-get="' . $href . '" hx-target="' . $this->e($target) . '" hx-swap="outerHTML"';

    return '<a class="admin-media-folder" href="' . $href . '"' . $hx . ($folder === $current ? ' aria-current="page"' : '') . '>'
        . $this->icon('folder') . '<span>' . $this->e($name) . '</span></a>';
};
$row = function (AtarCms\Media\MediaFolder $folder) use ($link, $editable): string {
    if (!$editable) {
        return $link($folder->id, $folder->name);
    }
    $label = $this->t('admin.media.edit_folder', ['name' => $folder->name]);

    return '<div class="admin-media-folder-row">' . $link($folder->id, $folder->name)
        . '<a class="admin-media-folder-edit" href="' . $this->e($this->url('admin.media.folders.edit', ['id' => $folder->id])) . '" title="' . $this->e($label) . '" data-dialog-open>'
        . $this->icon('pencil', $label) . '</a></div>';
};
$branch = function (?int $parent) use (&$branch, $tree, $row): string {
    $children = $tree->children($parent);
    if ($children === []) {
        return '';
    }
    $items = '';
    foreach ($children as $child) {
        $items .= '<li>' . $row($child) . $branch($child->id) . '</li>';
    }

    return '<ul class="admin-media-tree">' . $items . '</ul>';
};
?>
<nav class="admin-media-folders" aria-label="<?= $this->e($this->t('admin.media.folders.heading')) ?>">
    <ul class="admin-media-tree">
        <li><?= $link(null, $this->t('admin.media.folders.root')) ?><?= $branch(null) ?></li>
    </ul>
</nav>
