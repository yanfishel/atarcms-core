<?php
/**
 * Breadcrumbs: the home page, then the page's trail as its JSON-LD BreadcrumbList has it ($this->pageTrail(): the
 * archive, the sections or the parent pages, linked, and the page) when the page has an ancestor; else the template's
 * own $trail (sections from $this->termTrail(), root first, linked) and $items ([label, url] pairs). The last one is
 * the current page (no link). Previews have no page trail, so they show the template's items.
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Content\Term> $trail
 * @var list<array{0: string, 1: string|null}> $items
 */
$crumbs = [[$this->t('site.home'), $this->url('content.home.show')]];
$pageTrail = $this->pageTrail();
if (count($pageTrail) >= 2) {
    foreach ($pageTrail as $crumb) {
        $crumbs[] = [$crumb['name'], $crumb['url']];
    }
} else {
    foreach ($trail ?? [] as $term) {
        $crumbs[] = [$term->name, $this->termUrl($term)];
    }
    foreach ($items ?? [] as $item) {
        $crumbs[] = $item;
    }
}
$last = array_key_last($crumbs);
?>
<nav class="breadcrumbs" aria-label="<?= $this->e($this->t('site.breadcrumbs')) ?>">
    <ol>
        <?php foreach ($crumbs as $index => [$label, $url]): ?>
        <?php if ($index === $last || $url === null): ?>
        <li><span<?= $index === $last ? ' aria-current="page"' : '' ?>><?= $this->e($label) ?></span></li>
        <?php else: ?>
        <li><a href="<?= $this->e($url) ?>"><?= $this->e($label) ?></a></li>
        <?php endif ?>
        <?php endforeach ?>
    </ol>
</nav>
