<?php
/**
 * The page of a site form (/forms/{key}), drawn plainly so the tests can read every FormFieldView property.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Messages\Form\FormView $form
 */
$title = $this->t('forms.' . $form->key . '.title');
$this->layout('layouts/default', ['title' => $title, 'robots' => 'noindex']);
?>
<h1><?= $this->e($title) ?></h1>
<?php if ($form->sent): ?>
<p class="sent" role="status"><?= $this->e($this->t('forms.' . $form->key . '.sent')) ?></p>
<?php else: ?>
<?php if ($form->formError !== null): ?><p class="form-error" role="alert"><?= $this->e($this->t($form->formError)) ?></p><?php endif ?>
<form method="post" action="<?= $this->e($form->action) ?>">
<input type="hidden" name="<?= $this->e($form->tokenField) ?>" value="<?= $this->e($form->token) ?>">
<input type="text" name="<?= $this->e($form->honeypot) ?>" value="" tabindex="-1" autocomplete="off">
<?php foreach ($form->fields as $field): ?>
<div data-field="<?= $this->e($field->id) ?>" data-type="<?= $this->e($field->type) ?>" data-required="<?= $field->required ? '1' : '0' ?>" data-min="<?= $this->e($field->min) ?>" data-max="<?= $this->e($field->max) ?>"<?= $field->options !== [] ? ' data-options="' . $this->e(implode(' ', $field->options)) . '"' : '' ?>>
<input name="<?= $this->e($field->name) ?>" value="<?= $this->e($field->value) ?>">
<?php if ($field->error !== null): ?><p class="error" id="<?= $this->e($field->errorId) ?>"><?= $this->e($this->t($field->error, $field->params)) ?></p><?php endif ?>
</div>
<?php endforeach ?>
</form>
<?php endif ?>
