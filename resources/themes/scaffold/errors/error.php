<?php
/**
 * Any other error (403, 429, 500…). On a 5xx page the layout reads nothing from the database.
 *
 * @var AtarCms\View\Template $this
 * @var int $status
 */
$this->layout('layouts/default', ['title' => $this->t('site.error'), 'status' => $status]);
?>
<h1><?= $this->e($this->t('site.error')) ?></h1>
<p><?= $this->e($this->t('site.error_text')) ?></p>
