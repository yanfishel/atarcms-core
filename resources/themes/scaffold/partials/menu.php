<?php
/**
 * A menu level as a list; children nest (theme.php "menus", $this->menu()).
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Content\Menu\MenuLink> $links
 */
?>
<?php if ($links !== []): ?>
<ul class="menu">
    <?php foreach ($links as $link): ?>
    <li<?= $link->current || $link->ancestorOfCurrent ? ' class="is-current"' : '' ?>>
        <a href="<?= $this->e($link->url) ?>"<?= $link->current ? ' aria-current="page"' : '' ?><?= $link->newTab ? ' target="_blank" rel="noopener"' : '' ?>><?= $this->e($link->label) ?></a>
        <?= $this->partial('partials/menu', ['links' => $link->children]) ?>
    </li>
    <?php endforeach ?>
</ul>
<?php endif ?>
