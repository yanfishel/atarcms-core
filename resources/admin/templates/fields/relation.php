<?php
$chosen = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
$chosen = array_map(static fn($id): string => is_scalar($id) ? trim((string) $id) : '', $chosen);
$listed = array_map(static fn($id): string => (string) $id, array_keys($options));
?>
<select id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>[]" multiple size="6"<?= $aria ?>>
    <?php foreach ($options as $optionId => $title): ?>
    <option value="<?= $this->e($optionId) ?>"<?= in_array((string) $optionId, $chosen, true) ? ' selected' : '' ?>><?= $this->e($title) ?></option>
    <?php endforeach ?>
</select>
<?php foreach ($kept as $keptId): ?>
<?php if (in_array((string) $keptId, $chosen, true) && !in_array((string) $keptId, $listed, true)): ?>
<input type="hidden" name="<?= $this->e($name) ?>[]" value="<?= $this->e($keptId) ?>">
<?php endif ?>
<?php endforeach ?>
