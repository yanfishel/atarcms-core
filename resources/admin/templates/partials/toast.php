<?php
/**
 * One toast: the layout prints the flash messages with it, the media fragments put an upload success inside a
 * <template data-upload-toast> that media.js moves into the toast stack, and the tree editor keeps its save results in
 * <template>s that tree.js clones into the stack (shell.js wires every toast). The stack is the polite live region,
 * so a toast added later is announced; an error is also an alert (assertive). An $action adds a button under the text
 * ([data-toast-action]) that the script showing the toast wires; a $form adds a plain POST form instead (it works
 * without JavaScript: the offer to copy an item for a missing translation; its machine translation button, Plan 38, is
 * rendered hidden before the copy and only translate.js shows it). The form's buttons are one wrapping row of compact
 * buttons with short labels (the full label is the accessible name and the tooltip): Translate is the primary and Copy
 * beside it a link-style button; when Translate stays hidden, admin.css keeps Copy the primary. Only a success closes
 * by itself.
 *
 * @var AtarCms\View\Template $this
 * @var string $type "success", "error" or "info"
 * @var string $text the translated message
 * @var string|null $action (optional) the translated label of the action button
 * @var AtarCms\Admin\ToastForm|null $form (optional) a form under the text
 */
$action ??= null;
$form ??= null;
$icon = match ($type) {
    'success' => 'check',
    'info' => 'languages',
    default => 'alert-circle',
};
?>
<div class="admin-toast admin-toast-<?= $this->e($type) ?>"<?= $type === 'error' ? ' role="alert"' : '' ?> data-toast><?= $this->icon($icon) ?><?php if ($action === null && $form === null): ?><p class="admin-toast-text"><?= $this->e($text) ?></p><?php elseif ($form !== null): ?><div class="admin-toast-body"><p class="admin-toast-text"><?= $this->e($text) ?></p><form class="admin-toast-form" method="post" action="<?= $this->e($form->action) ?>"><?= $this->csrfField() ?><?php foreach ($form->fields as $name => $value): ?><input type="hidden" name="<?= $this->e($name) ?>" value="<?= $this->e($value) ?>"><?php endforeach ?><?php if ($form->machineFrom !== null): ?><button type="submit" class="admin-button admin-button-primary admin-button-compact" aria-label="<?= $this->e($this->t('admin.machine.offer')) ?>" title="<?= $this->e($this->t('admin.machine.offer')) ?>" name="machine" value="1" data-machine-offer data-machine-from="<?= $this->e($form->machineFrom) ?>" data-machine-to="<?= $this->e($form->fields['locale'] ?? '') ?>" hidden><?= $this->icon('languages') ?><?= $this->e($this->t('admin.machine.offer_short')) ?></button><?php endif ?><button type="submit" class="admin-button admin-button-primary admin-button-compact admin-toast-copy"<?php if ($form->shortLabel !== null): ?> aria-label="<?= $this->e($this->t($form->label, $form->labelParams)) ?>" title="<?= $this->e($this->t($form->label, $form->labelParams)) ?>"<?php endif ?>><?= $this->icon('copy') ?><?= $this->e($this->t($form->shortLabel ?? $form->label, $form->labelParams)) ?></button></form></div><?php else: ?><div class="admin-toast-body"><p class="admin-toast-text"><?= $this->e($text) ?></p><button type="button" class="admin-button admin-button-secondary admin-button-small" data-toast-action><?= $this->e($action) ?></button></div><?php endif ?><button type="button" class="admin-toast-close" data-toast-close><?= $this->icon('x', $this->t('admin.layout.close')) ?></button></div>
