<?php
/**
 * The page of a site form (/forms/{key}, AtarCms\Messages\Form\FormView): where a dialog's trigger link leads without
 * JavaScript. Its title and intro, the fields (partials/form-fields.php) and the submit button; after a refusal the
 * typed text and the errors, after a successful send the thanks instead of the form. Never indexed.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Messages\Form\FormView $form
 */
$prefix = 'forms.' . $form->key . '.';
$heading = $this->t($prefix . 'title');
$this->layout('layouts/default', ['title' => $heading, 'robots' => 'noindex']);
?>
<article class="form-page">
    <header class="page-header">
        <h1><?= $this->e($heading) ?></h1>
    </header>
    <?php if ($form->sent): ?>
    <p class="notice" role="status"><?= $this->e($this->t($prefix . 'sent')) ?></p>
    <?php else: ?>
    <p class="form-intro"><?= $this->e($this->t($prefix . 'intro')) ?></p>
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
</article>
