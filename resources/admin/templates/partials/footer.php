<?php
/**
 * The page footer, the last thing on every admin page, guest pages included: quiet links at the start (the AI guide
 * for signed-in users; more may join) and the year with the fishart mark at the end. No background — it just ends
 * the page.
 *
 * @var AtarCms\View\Template $this
 */
?>
<footer class="admin-footer">
    <?php if ($this->user() !== null): ?>
    <a class="admin-footer-link" href="<?= $this->e($this->url('admin.ai_guide')) ?>"><?= $this->e($this->t('admin.layout.ai_guide')) ?></a>
    <?php endif ?>
    <p class="admin-footer-brand"><?= $this->e(date('Y')) ?> © <a class="admin-footer-brand-link" href="https://fishart.co.il" target="_blank" rel="noopener"><img class="admin-fishart" src="<?= $this->e($this->asset('fishart.png')) ?>" alt="fishart" height="16"></a></p>
</footer>
