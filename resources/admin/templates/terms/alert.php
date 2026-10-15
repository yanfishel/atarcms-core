<?php
/**
 * The term form's summary: "fix the fields" with the number of its inputs that have an error. Errors without a field
 * of the form (a stale form, a language, the order, a crafted parent) are error toasts (FormErrors::split(), the
 * caller passes only the placed ones here).
 *
 * @var AtarCms\View\Template $this
 * @var array<string, string> $errors the errors of the inputs the form shows
 */
?>
<?php if ($errors !== []): ?>
<div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t('admin.form.fix_fields', ['count' => count($errors)])) ?></p></div>
<?php endif ?>
