<?php
/**
 * The machine translation panel of a copy's form (Plan 38; the entry form and the full term and section form, inside
 * the form, first): rendered hidden; translate.js shows it only where the browser's on-device Translator API can
 * translate the pair, and translates the named inputs in place. The note says it is machine translation to check; the
 * button is the user gesture a model download needs; the progress bar shows the download, then the share of the texts
 * done. The toasts are templates the script clones into the page's stack. Without JavaScript nothing shows and the
 * form is the plain copy.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Admin\MachineTranslation $machine
 */
$language = $this->languageName($machine->from);
$json = static fn(array $names): string => json_encode($names, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div class="admin-machine" data-machine data-machine-from="<?= $this->e($machine->from) ?>" data-machine-to="<?= $this->e($machine->to) ?>"<?= $machine->start ? ' data-machine-start' : '' ?><?= $machine->slug ? ' data-machine-slug' : '' ?> data-machine-fields="<?= $this->e($json($machine->fields)) ?>" data-machine-slides="<?= $this->e($json($machine->slides)) ?>" hidden>
    <?= $this->icon('languages') ?>
    <div class="admin-machine-body">
        <p class="admin-machine-note"><?= $this->e($this->t('admin.machine.note', ['language' => $language])) ?></p>
        <div class="admin-machine-actions">
            <button type="button" class="admin-button admin-button-secondary admin-button-small" data-machine-run><?= $this->icon('languages') ?><?= $this->e($this->t('admin.machine.run', ['language' => $language])) ?></button>
            <progress class="admin-machine-progress" max="1" value="0" aria-label="<?= $this->e($this->t('admin.machine.progress')) ?>" data-machine-progress hidden></progress>
        </div>
    </div>
    <template data-machine-done><?= $this->partial('partials/toast', ['type' => 'success', 'text' => $this->t('admin.machine.done', ['language' => $language])]) ?></template>
    <template data-machine-failed><?= $this->partial('partials/toast', ['type' => 'error', 'text' => $this->t('admin.machine.failed')]) ?></template>
</div>
