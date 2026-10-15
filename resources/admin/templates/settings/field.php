<?php
/**
 * One field of the site settings screen (SettingsForm::fields()): its label (with the language badge when the field is
 * kept per language), its input, its help line and its error. The accessibility toolbar (kind "toolbar") is one group
 * of its switch and its corner (kind "position", a dropdown of names, media/dropdown.php).
 *
 * @var AtarCms\View\Template $this
 * @var string $name the input name and id
 * @phpstan-import-type SettingsField from AtarCms\Admin\Settings\SettingsForm
 * @var SettingsField $field
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var array<int, AtarCms\Media\Media> $images
 * @var string $locale
 */
$invalid = isset($errors[$name]);
$aria = $invalid ? ' aria-invalid="true" aria-describedby="' . $this->e($name) . '-error"' : '';
$value = $values[$name] ?? '';
$max = $field['max'] === null ? '' : ' maxlength="' . $this->e($field['max']) . '"';
$label = $this->t($field['label']);
$badge = $field['locale']
    ? trim($this->partial('partials/locale-badge', ['locale' => $locale, 'title' => $this->t('admin.settings.badge_title', ['language' => $this->languageName($locale)])]))
    : '';
// A field kept per language holds text of that language (lang and dir), whatever the interface speaks.
$lang = $field['locale'] ? $this->langAttrs($locale) : ($field['ltr'] ? ' dir="ltr"' : '');
?>
<?php if ($field['kind'] === 'position'): ?>
<?php // The toolbar's corner is drawn inside the toolbar's group (kind "toolbar"). ?>
<?php elseif ($field['kind'] === 'toolbar'): ?>
<?php
// The accessibility toolbar: a heading with its icon, then one row with the switch (On|Off, named by the
// heading) and the corner, then one help line. The corners are logical values, named as in left-to-right pages.
$position = 'accessibility_toolbar_position';
$positionInvalid = isset($errors[$position]);
$corners = [];
foreach (AtarCms\Settings\Settings::TOOLBAR_POSITIONS as $corner) {
    $corners[] = ['value' => $corner, 'label' => $this->t('admin.settings.toolbar_position.' . str_replace('-', '_', $corner)), 'icon' => null];
}
?>
<div class="admin-field<?= $invalid || $positionInvalid ? ' admin-field-invalid' : '' ?>" role="group" aria-labelledby="<?= $this->e($name) ?>-label">
    <span class="admin-field-label" id="<?= $this->e($name) ?>-label"><?= $this->icon($field['icon']) ?><?= $this->e($label) ?></span>
    <div class="admin-toolbar-row">
        <label class="admin-toggle" for="<?= $this->e($name) ?>"><input type="checkbox" role="switch" id="<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" value="1"<?= $value === '1' ? ' checked' : '' ?> aria-labelledby="<?= $this->e($name) ?>-label"<?= $aria ?>><span class="admin-toggle-on" aria-hidden="true"><?= $this->e($this->t('admin.settings.toolbar.on')) ?></span><span class="admin-toggle-off" aria-hidden="true"><?= $this->e($this->t('admin.settings.toolbar.off')) ?></span></label>
        <span class="admin-toolbar-position"><span class="admin-toolbar-position-label" id="<?= $this->e($position) ?>-label"><?= $this->e($this->t('admin.settings.toolbar.position')) ?></span><?= $this->partial('media/dropdown', ['name' => $position, 'label' => $this->t('admin.settings.field.accessibility_toolbar_position'), 'options' => $corners, 'current' => $values[$position] ?? '', 'show' => 'text', 'aria' => $positionInvalid ? ' aria-invalid="true" aria-describedby="' . $this->e($position) . '-error"' : '']) ?></span>
    </div>
    <?php if ($field['help'] !== null): ?>
    <span class="admin-help"><?= $this->e($this->t($field['help'])) ?></span>
    <?php endif ?>
    <?php foreach ([$name, $position] as $input): ?>
    <?php if (isset($errors[$input])): ?>
    <span class="admin-field-error" id="<?= $this->e($input) ?>-error"><?= $this->e($this->t($errors[$input], $params[$input] ?? [])) ?></span>
    <?php endif ?>
    <?php endforeach ?>
</div>
<?php else: ?>
<div class="admin-field<?= $invalid ? ' admin-field-invalid' : '' ?>">
    <?php if ($field['kind'] === 'switch'): ?>
    <label class="admin-toggle" for="<?= $this->e($name) ?>"><input type="checkbox" role="switch" id="<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" value="1"<?= $value === '1' ? ' checked' : '' ?><?= $aria ?>><?= $this->e($label) ?></label>
    <?php else: ?>
    <label for="<?= $this->e($name) ?>"><?= $this->icon($field['icon']) ?><?= $this->e($label) ?><?= $badge ?></label>
    <?php endif ?>
    <?php if ($field['kind'] === 'textarea'): ?>
    <textarea id="<?= $this->e($name) ?>" name="<?= $this->e($name) ?>"<?= $max ?> rows="3"<?= $aria ?><?= $lang ?>><?= $this->e($value) ?></textarea>
    <?php elseif ($field['kind'] === 'color'): ?>
    <?php $on = AtarCms\Admin\Settings\SettingsForm::THEME_COLOR_ON; ?>
    <div class="admin-color-field">
        <input type="color" id="<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e(preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : '#1a73e8') ?>"<?= $aria ?>>
        <label class="admin-check"><input type="checkbox" name="<?= $this->e($on) ?>" value="1"<?= ($values[$on] ?? '') === '1' ? ' checked' : '' ?>> <?= $this->e($this->t('admin.settings.theme_color_on')) ?></label>
        <?php
        // The admin colour presets as ready-made theme colours (JavaScript only: media.js copies the chosen one into
        // the colour input and turns the colour on; the radios themselves are never stored).
        $presets = [];
        foreach (AtarCms\Settings\AdminAccent::cases() as $accent) {
            $presets[] = ['value' => $accent->logoColor(), 'label' => $this->t('admin.accent.' . $accent->value), 'icon' => null, 'swatch' => $accent->value];
        }
        $current = ($values[$on] ?? '') === '1' ? strtolower($value) : '';
        ?>
        <span class="admin-color-presets" data-color-presets>
            <?= $this->partial('media/dropdown', ['name' => 'theme_color_preset', 'label' => $this->t('admin.settings.theme_color_preset'), 'options' => $presets, 'current' => $current, 'show' => 'both', 'placeholder' => $this->t('admin.settings.theme_color_preset')]) ?>
        </span>
    </div>
    <?php elseif ($field['kind'] === 'media'): ?>
    <?= $this->partial('media/field', ['name' => $name, 'id' => $name, 'value' => $value, 'media' => $images, 'multiple' => false, 'kind' => 'image', 'invalid' => $invalid]) ?>
    <?php elseif ($field['kind'] !== 'switch'): ?>
    <input<?= $field['kind'] === 'text' ? '' : ' type="' . $this->e($field['kind']) . '"' ?> id="<?= $this->e($name) ?>" name="<?= $this->e($name) ?>"<?= $max ?> value="<?= $this->e($value) ?>"<?= $aria ?><?= $lang ?>>
    <?php endif ?>
    <?php if ($field['help'] !== null): ?>
    <span class="admin-help"><?= $this->e($this->t($field['help'], ['max' => $field['max'] ?? 0])) ?></span>
    <?php endif ?>
    <?php if ($invalid): ?>
    <span class="admin-field-error" id="<?= $this->e($name) ?>-error"><?= $this->e($this->t($errors[$name], $params[$name] ?? [])) ?></span>
    <?php endif ?>
</div>
<?php endif ?>
