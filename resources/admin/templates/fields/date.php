<input type="date" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e(is_string($value) ? $value : '') ?>"<?= $field->required ? ' required' : '' ?><?= $aria ?>>
