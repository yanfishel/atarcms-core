<?php
/**
 * One level of a menu; a link with children gets them as a nested list (a dropdown in the main menu, an indented
 * list on phones). A section's own link leads to its first subsection.
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Content\Menu\MenuLink> $links
 */
?>
<ul class="menu">
    <?php foreach ($links as $link): ?>
    <?php $classes = array_filter([$link->children !== [] ? 'has-children' : '', $link->current ? 'is-current' : ($link->ancestorOfCurrent ? 'is-ancestor' : '')]); ?>
    <li<?= $classes !== [] ? ' class="' . $this->e(implode(' ', $classes)) . '"' : '' ?>>
        <a href="<?= $this->e($link->url) ?>"<?= $link->current ? ' aria-current="page"' : '' ?><?= $link->newTab ? ' target="_blank" rel="noopener"' : '' ?>><?= $this->e($link->label) ?><?= $link->children !== [] ? $this->partial('partials/icon', ['name' => 'chevron-down']) : '' ?></a>
        <?php if ($link->children !== []): ?>
        <?= $this->partial('partials/menu', ['links' => $link->children]) ?>
        <?php endif ?>
    </li>
    <?php endforeach ?>
</ul>
