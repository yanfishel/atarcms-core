<?php
/**
 * The page links of a list (AtarCms\Admin\Pagination::window()); nothing when there is one page. With $target the
 * links load through htmx into that element (the media picker), and still work as plain links.
 *
 * @var AtarCms\View\Template $this
 * @var int $page the current page
 * @var int $pages the last page
 * @var callable(int): string $url page number => its URL
 * @var string|null $target (optional) the hx-target of the links
 */
$target ??= null;
$link = function (int $number, string $content, string $extra = '') use ($url, $target): string {
    $href = $this->e($url($number));
    $hx = $target === null ? '' : ' hx-get="' . $href . '" hx-target="' . $this->e($target) . '" hx-swap="outerHTML"';

    return '<a class="admin-page-link" href="' . $href . '"' . $extra . $hx . '>' . $content . '</a>';
};
?>
<?php if ($pages > 1): ?>
<nav class="admin-pagination" aria-label="<?= $this->e($this->t('admin.pagination.label')) ?>">
    <?php if ($page > 1): ?>
    <?= $link($page - 1, $this->icon('chevron-left', $this->t('admin.pagination.previous')), ' rel="prev" title="' . $this->e($this->t('admin.pagination.previous')) . '"') ?>
    <?php endif ?>
    <?php foreach (AtarCms\Admin\Pagination::window($page, $pages) as $number): ?>
    <?php if ($number === null): ?>
    <span class="admin-page-gap" aria-hidden="true">…</span>
    <?php elseif ($number === $page): ?>
    <span class="admin-page-link" aria-current="page"><?= $this->e($number) ?></span>
    <?php else: ?>
    <?= $link($number, $this->e($number), ' aria-label="' . $this->e($this->t('admin.pagination.page', ['page' => $number])) . '"') ?>
    <?php endif ?>
    <?php endforeach ?>
    <?php if ($page < $pages): ?>
    <?= $link($page + 1, $this->icon('chevron-right', $this->t('admin.pagination.next')), ' rel="next" title="' . $this->e($this->t('admin.pagination.next')) . '"') ?>
    <?php endif ?>
    <span class="admin-pagination-summary"><?= $this->e($this->t('admin.pagination.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
</nav>
<?php endif ?>
