<?php
/** @var AtarCms\View\Template $this */
/** @var string $name */
/** @var string $id */
/** @var mixed $value */
/** @var array<int, AtarCms\Media\Media> $options the chosen media */
/** @var bool $invalid the field has an error */
?>
<?= $this->partial('media/field', ['name' => $name, 'id' => $id, 'value' => $value, 'media' => $options, 'multiple' => true, 'kind' => 'image', 'invalid' => $invalid]) ?>
