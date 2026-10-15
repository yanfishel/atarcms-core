<?php
/**
 * One chosen tag of the tag box (fields/tags.php): its name and the × that removes it. tags.js clones the empty one
 * of the widget's <template data-tags-pill> and sets the name and the button's name as properties.
 *
 * @var AtarCms\View\Template $this
 * @var string $name
 */
$label = $name === '' ? '' : $this->t('admin.tags.remove', ['name' => $name]);
?>
<li class="admin-tags-pill"><span class="admin-tags-pill-text"><?= $this->e($name) ?></span><button type="button" class="admin-tags-remove" data-tags-remove aria-label="<?= $this->e($label) ?>" title="<?= $this->e($label) ?>"><?= $this->icon('x') ?></button></li>
