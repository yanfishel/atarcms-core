<?php
$shown = is_string($value) ? $value : '';
if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $shown) === 1) {
    $shown = str_replace(' ', 'T', substr($shown, 0, 16));
}
?>
<input type="datetime-local" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e($shown) ?>"<?= $field->required ? ' required' : '' ?><?= $aria ?>>
