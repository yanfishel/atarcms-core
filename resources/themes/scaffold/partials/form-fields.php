<?php
/**
 * The fields of a site form ($form: AtarCms\Messages\Form\FormView), drawn by type in the form's order: a label
 * (forms.{key}.{field}, "(optional)" when not required), the input, and the error paragraph the input describes (empty
 * while there is no error; forms.js fills it in the dialog). A radio group is a fieldset; a select starts with an
 * empty choice. Then the honeypot (hidden by CSS, never type="hidden") and the signed token. Ids are "{form}-{field}"
 * (a radio option "{form}-{field}-option-{option}", so no option key meets "{form}-{field}-error"), so several forms
 * on one page never repeat one. Every value is the visitor's text: escaped.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Messages\Form\FormView $form
 */
$autocomplete = ['name' => 'name', 'email' => 'email', 'phone' => 'tel'];
$maxlength = static fn(AtarCms\Messages\Form\FormFieldView $field): int => match ($field->type) {
    'textarea' => 5000,
    'email' => 200,
    default => match ($field->name) {
        'name' => 100,
        'phone' => 50,
        default => 200,
    },
};
?>
<?php foreach ($form->fields as $field): ?>
<?php
$label = $this->t('forms.' . $form->key . '.' . $field->name);
$invalid = $field->error !== null;
$describe = ($invalid ? ' aria-invalid="true"' : '') . ' aria-describedby="' . $this->e($field->errorId) . '"';
$optional = $field->required ? '' : ' <span class="field-optional">' . $this->e($this->t('forms.optional')) . '</span>';
?>
<?php if ($field->type === 'radio'): ?>
<fieldset class="field field-choices<?= $invalid ? ' field-invalid' : '' ?>" aria-describedby="<?= $this->e($field->errorId) ?>">
    <legend><?= $this->e($label) ?><?= $optional ?></legend>
    <?php foreach ($field->options as $option): ?>
    <label class="choice"><input type="radio" id="<?= $this->e($field->id . '-option-' . $option) ?>" name="<?= $this->e($field->name) ?>" value="<?= $this->e($option) ?>"<?= $field->value === $option ? ' checked' : '' ?><?= $field->required ? ' required' : '' ?><?= $invalid ? ' aria-invalid="true"' : '' ?>> <?= $this->e($this->t('forms.' . $form->key . '.' . $field->name . '.' . $option)) ?></label>
    <?php endforeach ?>
    <p class="field-error" id="<?= $this->e($field->errorId) ?>" data-error-for="<?= $this->e($field->name) ?>"><?= $invalid ? $this->e($this->t((string) $field->error, $field->params)) : '' ?></p>
</fieldset>
<?php elseif ($field->type === 'checkbox'): ?>
<div class="field field-check<?= $invalid ? ' field-invalid' : '' ?>">
    <input type="checkbox" id="<?= $this->e($field->id) ?>" name="<?= $this->e($field->name) ?>" value="1"<?= $field->required ? ' required' : '' ?><?= $field->value !== '' ? ' checked' : '' ?><?= $describe ?>>
    <label for="<?= $this->e($field->id) ?>"><?= $this->e($label) ?><?= $optional ?></label>
    <p class="field-error" id="<?= $this->e($field->errorId) ?>" data-error-for="<?= $this->e($field->name) ?>"><?= $invalid ? $this->e($this->t((string) $field->error, $field->params)) : '' ?></p>
</div>
<?php else: ?>
<div class="field<?= $invalid ? ' field-invalid' : '' ?>">
    <label for="<?= $this->e($field->id) ?>"><?= $this->e($label) ?><?= $optional ?></label>
    <?php if ($field->type === 'select'): ?>
    <select id="<?= $this->e($field->id) ?>" name="<?= $this->e($field->name) ?>"<?= $field->required ? ' required' : '' ?><?= $describe ?>>
        <option value=""><?= $this->e($this->t('forms.choose')) ?></option>
        <?php foreach ($field->options as $option): ?>
        <option value="<?= $this->e($option) ?>"<?= $field->value === $option ? ' selected' : '' ?>><?= $this->e($this->t('forms.' . $form->key . '.' . $field->name . '.' . $option)) ?></option>
        <?php endforeach ?>
    </select>
    <?php elseif ($field->type === 'textarea'): ?>
    <textarea id="<?= $this->e($field->id) ?>" name="<?= $this->e($field->name) ?>" rows="5" maxlength="<?= $this->e($maxlength($field)) ?>"<?= $field->required ? ' required' : '' ?><?= $describe ?>><?= $this->e($field->value) ?></textarea>
    <?php elseif ($field->type === 'date' || $field->type === 'time'): ?>
    <input type="<?= $this->e($field->type) ?>" id="<?= $this->e($field->id) ?>" name="<?= $this->e($field->name) ?>" value="<?= $this->e($field->value) ?>"<?= $field->min !== null ? ' min="' . $this->e($field->min) . '"' : '' ?><?= $field->max !== null ? ' max="' . $this->e($field->max) . '"' : '' ?><?= $field->required ? ' required' : '' ?><?= $describe ?>>
    <?php else: ?>
    <input type="<?= $this->e($field->type) ?>" id="<?= $this->e($field->id) ?>" name="<?= $this->e($field->name) ?>" value="<?= $this->e($field->value) ?>" maxlength="<?= $this->e($maxlength($field)) ?>"<?= isset($autocomplete[$field->name]) ? ' autocomplete="' . $this->e($autocomplete[$field->name]) . '"' : '' ?><?= $field->required ? ' required' : '' ?><?= $describe ?>>
    <?php endif ?>
    <p class="field-error" id="<?= $this->e($field->errorId) ?>" data-error-for="<?= $this->e($field->name) ?>"><?= $invalid ? $this->e($this->t((string) $field->error, $field->params)) : '' ?></p>
</div>
<?php endif ?>
<?php endforeach ?>
<div class="field-trap" aria-hidden="true">
    <label for="<?= $this->e($form->key . '-' . $form->honeypot) ?>"><?= $this->e($this->t('forms.trap')) ?></label>
    <input type="text" id="<?= $this->e($form->key . '-' . $form->honeypot) ?>" name="<?= $this->e($form->honeypot) ?>" value="" tabindex="-1" autocomplete="off">
</div>
<input type="hidden" name="<?= $this->e($form->tokenField) ?>" value="<?= $this->e($form->token) ?>">
