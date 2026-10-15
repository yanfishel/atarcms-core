<?php
/**
 * Every other error page (403, 419, 500…). A 5xx page may be caused by the database: it calls no helper that reads it
 * (the layout leaves out the menus and the site settings for $status 500 and above).
 *
 * @var AtarCms\View\Template $this
 * @var int $status
 * @var string $reason
 */
$key = 'http.status.' . $status;
$label = $this->hasTranslation($key) ? $this->t($key) : $reason;
$this->layout('layouts/default', ['title' => $label]);
?>
<section class="error-page">
    <p class="error-code" aria-hidden="true"><?= $this->e($status) ?></p>
    <h1><?= $this->e($label) ?></h1>
    <?php if ($status >= 500): ?>
    <p><?= $this->e($this->t('site.error_text')) ?></p>
    <?php endif ?>
    <p><a class="button" href="<?= $this->e($this->url('content.home.show')) ?>"><?= $this->e($this->t('site.back_home')) ?></a></p>
</section>
