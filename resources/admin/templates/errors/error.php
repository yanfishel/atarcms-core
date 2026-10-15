<?php
/** @var AtarCms\View\Template $this */
/** @var int $status */
/** @var string $reason */
$key = 'http.status.' . $status;
$label = $this->t($key);
if ($label === $key) {
    $label = $reason;
}
$explain = $this->hasTranslation('admin.error.explain.' . $status) ? 'admin.error.explain.' . $status : 'admin.error.explain.default';
$this->layout('layouts/admin', ['title' => $status . ' ' . $label]);
?>
<section class="admin-error">
    <p class="admin-error-code" aria-hidden="true"><?= $this->e($status) ?></p>
    <h1><?= $this->e($label) ?></h1>
    <p><?= $this->e($this->t($explain)) ?></p>
    <a class="admin-button admin-button-primary" href="<?= $this->e($this->url('admin.dashboard')) ?>"><?= $this->icon('layout-dashboard') ?><?= $this->e($this->t('admin.error.back')) ?></a>
</section>
