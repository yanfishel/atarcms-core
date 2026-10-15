<?php
/**
 * The page of a site form (/forms/{key}, AtarCms\Messages\Form\FormView): its title, intro and fields, or the thanks
 * after a successful send. Never indexed. A dialog that opens the form from any page is a recipe of the theme guide.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Messages\Form\FormView $form
 */
$prefix = 'forms.' . $form->key . '.';
$this->layout('layouts/default', ['title' => $this->t($prefix . 'title'), 'robots' => 'noindex']);
?>
<h1><?= $this->e($this->t($prefix . 'title')) ?></h1>
<?php if ($form->sent): ?>
<p class="notice" role="status"><?= $this->e($this->t($prefix . 'sent')) ?></p>
<?php else: ?>
<p><?= $this->e($this->t($prefix . 'intro')) ?></p>
<?php if ($form->hasErrors()): ?>
<div class="form-errors" role="alert">
    <p><?= $this->e($form->formError !== null ? $this->t($form->formError) : $this->t('forms.errors')) ?></p>
</div>
<?php endif ?>
<form class="form" method="post" action="<?= $this->e($form->action) ?>" novalidate>
    <?= $this->partial('partials/form-fields', ['form' => $form]) ?>
    <button type="submit" class="button"><?= $this->e($this->t($prefix . 'submit')) ?></button>
</form>
<?php endif ?>
