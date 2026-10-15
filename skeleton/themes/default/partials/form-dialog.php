<?php
/**
 * A site form in a modal dialog ($key: a form of config/forms.php), included once per form in the layout. Any link
 * with data-form-dialog="form-{key}" and the form's page as its href opens it (assets/js/forms.js); without JavaScript
 * the link opens the page. A header with the title and a close button (a method="dialog" form: it closes without
 * scripts, Esc too), a scrolling body with the intro, the form error, the fields and the thanks (live regions, empty
 * until forms.js writes into them), and a footer with the submit button. On the form's own page (and for an unknown
 * form) it draws nothing, so no id repeats; nor for the contact form, which lives on its page. The module script runs once however often the partial is included.
 *
 * @var AtarCms\View\Template $this
 * @var string $key
 */
$form = $this->form($key);
?>
<?php // The contact form is no dialog form: its endpoint answers with the contact page, never JSON. ?>
<?php if ($form !== null && !$form->onItsPage && $form->key !== 'contact'): ?>
<?php
$prefix = 'forms.' . $form->key . '.';
$id = 'form-' . $form->key;
?>
<dialog class="form-dialog" id="<?= $this->e($id) ?>" aria-labelledby="<?= $this->e($id) ?>-title" data-sending="<?= $this->e($this->t('forms.sending')) ?>" data-network-error="<?= $this->e($this->t('forms.network_error')) ?>">
    <div class="form-dialog-header">
        <h2 id="<?= $this->e($id) ?>-title"><?= $this->e($this->t($prefix . 'title')) ?></h2>
        <form method="dialog">
            <button type="submit" class="form-dialog-close" aria-label="<?= $this->e($this->t('forms.close')) ?>" title="<?= $this->e($this->t('forms.close')) ?>"><?= $this->partial('partials/icon', ['name' => 'x']) ?></button>
        </form>
    </div>
    <form class="form form-dialog-form" method="post" action="<?= $this->e($form->action) ?>" novalidate>
        <div class="form-dialog-body">
            <p class="form-intro"><?= $this->e($this->t($prefix . 'intro')) ?></p>
            <p class="form-errors" role="alert" data-form-error></p>
            <div class="form-dialog-fields" data-form-fields>
                <?= $this->partial('partials/form-fields', ['form' => $form]) ?>
            </div>
            <p class="notice" role="status" data-form-status></p>
        </div>
        <div class="form-dialog-footer">
            <button type="submit" class="button" data-form-submit><?= $this->e($this->t($prefix . 'submit')) ?></button>
        </div>
    </form>
</dialog>
<script type="module" src="<?= $this->e($this->asset('js/forms.js')) ?>"></script>
<?php endif ?>
