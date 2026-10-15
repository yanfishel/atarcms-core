<?php $selected = is_scalar($value) ? (string) $value : ''; ?>
<select id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>"<?= $field->required ? ' required' : '' ?><?= $aria ?>>
    <option value="">—</option>
    <?php foreach ($field->options as $option => $label): ?>
    <option value="<?= $this->e($option) ?>"<?= (string) $option === $selected ? ' selected' : '' ?>><?= $this->e($this->label($label)) ?></option>
    <?php endforeach ?>
</select>
