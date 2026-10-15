<?php
/**
 * The inputs of one way to enter the map (GoogleMapInput: embed, link, address, coordinates) with their help lines and
 * errors, in the dialog's tab or on the full page's card. Addresses, codes and numbers are written left to right.
 *
 * @var AtarCms\View\Template $this
 * @var string $method
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var bool $autofocus whether the first input takes the focus (the dialog's open tab)
 */
$value = static fn(string $name): string => $values[$name] ?? '';
$fieldClass = static fn(string $name): string => 'admin-field' . (isset($errors[$name]) ? ' admin-field-invalid' : '');
$aria = static fn(string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="map-' . $name . '-error"' : '';
$focus = $autofocus ? ' autofocus' : '';
$error = function (string $name) use ($errors, $params): string {
    return isset($errors[$name])
        ? '<span class="admin-field-error" id="map-' . $this->e($name) . '-error">' . $this->e($this->t($errors[$name], $params[$name] ?? [])) . '</span>'
        : '';
};
?>
<?php if ($method === 'embed'): ?>
<div class="<?= $fieldClass('embed') ?>">
    <label for="map-embed"><?= $this->icon('app-window') ?><?= $this->e($this->t('admin.map.field.embed')) ?></label>
    <textarea id="map-embed" name="embed" rows="5" dir="ltr"<?= $aria('embed') ?><?= $focus ?>><?= $this->e($value('embed')) ?></textarea>
    <span class="admin-help"><?= $this->e($this->t('admin.map.help_text.embed')) ?></span>
    <?= $error('embed') ?>
</div>
<?php elseif ($method === 'link'): ?>
<div class="<?= $fieldClass('link') ?>">
    <label for="map-link"><?= $this->icon('link') ?><?= $this->e($this->t('admin.map.field.link')) ?></label>
    <input id="map-link" name="link" inputmode="url" maxlength="4000" value="<?= $this->e($value('link')) ?>" dir="ltr"<?= $aria('link') ?><?= $focus ?>>
    <span class="admin-help"><?= $this->e($this->t('admin.map.help_text.link')) ?></span>
    <?= $error('link') ?>
</div>
<?php elseif ($method === 'address'): ?>
<div class="<?= $fieldClass('address') ?>">
    <label for="map-address"><?= $this->icon('map-pin') ?><?= $this->e($this->t('admin.map.field.address')) ?></label>
    <input id="map-address" name="address" autocomplete="off" maxlength="<?= $this->e(AtarCms\Settings\GoogleMap::MAX_QUERY) ?>" value="<?= $this->e($value('address')) ?>"<?= $aria('address') ?><?= $focus ?>>
    <span class="admin-help"><?= $this->e($this->t('admin.map.help_text.address')) ?></span>
    <?= $error('address') ?>
</div>
<?php else: ?>
<div class="admin-map-point">
    <div class="<?= $fieldClass('latitude') ?>">
        <label for="map-latitude"><?= $this->icon('hash') ?><?= $this->e($this->t('admin.map.field.latitude')) ?></label>
        <input id="map-latitude" name="latitude" inputmode="decimal" maxlength="40" value="<?= $this->e($value('latitude')) ?>" dir="ltr"<?= $aria('latitude') ?><?= $focus ?>>
        <?= $error('latitude') ?>
    </div>
    <div class="<?= $fieldClass('longitude') ?>">
        <label for="map-longitude"><?= $this->icon('hash') ?><?= $this->e($this->t('admin.map.field.longitude')) ?></label>
        <input id="map-longitude" name="longitude" inputmode="decimal" maxlength="40" value="<?= $this->e($value('longitude')) ?>" dir="ltr"<?= $aria('longitude') ?>>
        <?= $error('longitude') ?>
    </div>
</div>
<p class="admin-help"><?= $this->e($this->t('admin.map.help_text.coordinates')) ?></p>
<div class="<?= $fieldClass('zoom') ?>">
    <label for="map-zoom"><?= $this->icon('search') ?><?= $this->e($this->t('admin.map.field.zoom')) ?></label>
    <input type="number" id="map-zoom" name="zoom" min="<?= $this->e(AtarCms\Settings\GoogleMap::MIN_ZOOM) ?>" max="<?= $this->e(AtarCms\Settings\GoogleMap::MAX_ZOOM) ?>" value="<?= $this->e($value('zoom')) ?>" dir="ltr"<?= $aria('zoom') ?>>
    <span class="admin-help"><?= $this->e($this->t('admin.map.help_text.zoom')) ?></span>
    <?= $error('zoom') ?>
</div>
<?php endif ?>
