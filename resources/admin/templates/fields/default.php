<input id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e(is_scalar($value) ? (string) $value : '') ?>"<?= $field->required ? ' required' : '' ?><?= $aria ?><?= $lang ?>>
