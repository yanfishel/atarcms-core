<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Media\Media $media */
/** @var array<string, string> $alt */
/** @var list<string> $locales */
/** @var array<string, string> $errors */
/** @var array<string, array<string, int|float|string>> $params */
/** @var string $version */
/** @var list<array{title: string|null, collection: string|null, locale: string|null, field: string|null, url: string|null}> $usages */
/** @var bool $canDelete */
/** @var string|null $uploadedBy the uploader's name, if the user still exists */
/** @var array{url: string|null, uses: list<string>}|null $settingsUse the site settings use the file (logo, icon, sharing image) */
/** @var string $address the file's absolute address */
/** @var list<AtarCms\Media\MediaFolder> $trail the file's folder and its ancestors, top-level first */
$breadcrumbs = [[$this->t('admin.media.title'), $this->url('admin.media')]];
foreach ($trail as $folder) {
    $breadcrumbs[] = [$folder->name, $this->url('admin.media', ['folder' => $folder->id])];
}
$breadcrumbs[] = [$media->originalName];
$this->layout('layouts/admin', [
    'title' => $this->t('admin.media.edit') . ' · ' . $media->originalName,
    'breadcrumbs' => $breadcrumbs,
    'scripts' => ['media.js'],
    'toasts' => AtarCms\Admin\FlashMessage::errors(array_intersect_key($errors, ['media' => true]), $params),
]);
$url = $this->mediaUrl($media);
$folder = $trail === [] ? null : $trail[count($trail) - 1];
?>
<?= $this->partial('partials/page-header', ['title' => $media->originalName]) ?>
<template data-copy-toast><?= trim($this->partial('partials/toast', ['type' => 'success', 'text' => $this->t('admin.media.copy.done')])) ?></template>
<template data-copy-failed><?= trim($this->partial('partials/toast', ['type' => 'error', 'text' => $this->t('admin.media.copy.failed')])) ?></template>
<?php if ($media->isImage()): ?>
<form class="admin-media-form" method="post" action="<?= $this->e($this->url('admin.media.update', ['id' => $media->id])) ?>">
    <?= $this->csrfField() ?>
    <input type="hidden" name="version" value="<?= $this->e($version) ?>">
<?php endif ?>
<div class="admin-media-item">
    <div class="admin-card admin-media-stage">
        <?php if ($media->isImage()): ?>
        <img class="admin-media-preview" src="<?= $this->e($url) ?>" alt="" width="<?= $this->e($media->width) ?>" height="<?= $this->e($media->height) ?>">
        <?php elseif ($media->isVideo()): ?>
        <video class="admin-media-player" controls preload="metadata" src="<?= $this->e($url) ?>"></video>
        <?php else: ?>
        <?= $this->partial('media/thumb', ['media' => $media]) ?>
        <?php endif ?>
    </div>
    <div class="admin-media-info">
        <section class="admin-card">
            <dl class="admin-media-details">
                <dt><?= $this->e($this->t('admin.media.details.name')) ?></dt>
                <dd><?= $this->e($media->originalName) ?></dd>
                <dt><?= $this->e($this->t('admin.media.details.type')) ?></dt>
                <dd><?= $this->e($media->mime) ?></dd>
                <?php if ($media->width !== null && $media->height !== null): ?>
                <dt><?= $this->e($this->t('admin.media.details.dimensions')) ?></dt>
                <dd><?= $this->e($this->t('admin.media.details.dimensions_value', ['width' => $media->width, 'height' => $media->height])) ?></dd>
                <?php endif ?>
                <dt><?= $this->e($this->t('admin.media.details.size')) ?></dt>
                <dd><?= $this->e($this->t('admin.media.details.size_value', ['kb' => round($media->size / 1024, 1)])) ?></dd>
                <dt><?= $this->e($this->t('admin.media.details.uploaded')) ?></dt>
                <dd><?= $this->e($this->readableDate($media->createdAt)) ?></dd>
                <?php if ($uploadedBy !== null): ?>
                <dt><?= $this->e($this->t('admin.media.details.uploaded_by')) ?></dt>
                <dd><?= $this->e($uploadedBy) ?></dd>
                <?php endif ?>
                <dt><?= $this->e($this->t('admin.media.details.folder')) ?></dt>
                <dd><a href="<?= $this->e($this->url('admin.media', $folder === null ? [] : ['folder' => $folder->id])) ?>"><?= $this->e(implode(' › ', [$this->t('admin.media.folders.root'), ...array_map(static fn(AtarCms\Media\MediaFolder $step): string => $step->name, $trail)])) ?></a></dd>
                <dt><?= $this->e($this->t('admin.media.details.address')) ?></dt>
                <dd><a href="<?= $this->e($url) ?>" target="_blank" rel="noopener"><?= $this->e($address) ?></a></dd>
            </dl>
            <button type="button" class="admin-button admin-button-secondary admin-media-copy" hidden data-copy-link="<?= $this->e($address) ?>"><?= $this->icon('copy') ?><?= $this->e($this->t('admin.media.copy.button')) ?></button>
        </section>
        <?php if ($media->isImage()): ?>
        <fieldset class="admin-card admin-media-alt">
            <legend class="admin-card-heading"><?= $this->e($this->t('admin.media.alt.legend')) ?></legend>
            <p class="admin-help"><?= $this->e($this->t('admin.media.alt.help')) ?></p>
            <?php foreach ($locales as $locale): ?>
            <div class="admin-field<?= isset($errors['alt.' . $locale]) ? ' admin-field-invalid' : '' ?>">
                <?php // One text per language: the label names it with the language's pill, the input carries its lang and dir. ?>
                <label for="alt-<?= $this->e($locale) ?>"><?= $this->icon('type') ?><?= $this->e($this->t('admin.media.alt.locale')) ?><?= trim($this->partial('partials/locale-badge', ['locale' => $locale, 'title' => $this->languageName($locale), 'keep' => true])) ?></label>
                <input id="alt-<?= $this->e($locale) ?>" name="alt[<?= $this->e($locale) ?>]" maxlength="255" value="<?= $this->e($alt[$locale] ?? '') ?>"<?= $this->langAttrs($locale) ?>>
                <?php if (isset($errors['alt.' . $locale])): ?>
                <span class="admin-field-error"><?= $this->e($this->t($errors['alt.' . $locale], $params['alt.' . $locale] ?? [])) ?></span>
                <?php endif ?>
            </div>
            <?php endforeach ?>
        </fieldset>
        <?php endif ?>
        <section class="admin-card">
            <h2 class="admin-card-heading"><?= $this->e($this->t('admin.media.usage.title')) ?></h2>
            <?= $this->partial('media/usages', ['usages' => $usages, 'settings' => $settingsUse]) ?>
        </section>
    </div>
</div>
<?php if ($canDelete || $media->isImage()): ?>
<div class="admin-action-bar">
    <p class="admin-action-bar-status"></p>
    <div class="admin-action-bar-buttons">
        <?php if ($canDelete): ?>
        <a class="admin-button admin-button-link admin-button-danger" href="<?= $this->e($this->url('admin.media.delete.confirm', ['id' => $media->id])) ?>"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.media.delete')) ?></a>
        <?php endif ?>
        <?php if ($media->isImage()): ?>
        <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($this->t('admin.media.save')) ?></button>
        <?php endif ?>
    </div>
</div>
<?php endif ?>
<?php if ($media->isImage()): ?>
</form>
<?php endif ?>
