<?php $checked = $value === true || $value === '1' || $value === 'on' || $value === 'true'; ?>
<label class="admin-check admin-check-field"><input type="checkbox" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" value="1"<?= $checked ? ' checked' : '' ?><?= $aria ?>> <?= $this->e($this->label($field->label)) ?></label>
