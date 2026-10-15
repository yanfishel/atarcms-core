<?php
/**
 * The contact form ($this->contactForm(), AtarCms\Messages\Form\FormView): no session and no cookie, a signed token
 * and a honeypot field people never see (partials/form-fields.php draws the fields). After a refusal the page comes
 * back with the typed text and the errors; after a successful send it shows the notice instead of the form.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Messages\Form\FormView $form
 */
?>
<section class="contact-form" id="contact" aria-labelledby="contact-title">
    <h2 id="contact-title"><?= $this->e($this->t('forms.contact.title')) ?></h2>
    <?php if ($form->sent): ?>
    <p class="notice" role="status"><?= $this->e($this->t('forms.contact.sent')) ?></p>
    <?php else: ?>
    <?php if ($form->hasErrors()): ?>
    <div class="form-errors" role="alert">
        <p><?= $this->e($form->formError !== null ? $this->t($form->formError) : $this->t('forms.errors')) ?></p>
    </div>
    <?php endif ?>
    <form class="form" method="post" action="<?= $this->e($form->action) ?>#contact" novalidate>
        <?= $this->partial('partials/form-fields', ['form' => $form]) ?>
        <p class="form-note"><?= $this->e($this->t('forms.note')) ?></p>
        <button type="submit" class="button"><?= $this->e($this->t('forms.contact.submit')) ?></button>
    </form>
    <?php endif ?>
</section>
