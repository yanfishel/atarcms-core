<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Content\Taxonomy $taxonomy */
/** @var list<array{term: AtarCms\Content\Term, depth: int}> $nodes */
/** @var list<string> $chosen */
/** @var string|null $error */
/** @var bool $single the taxonomy of the route's placeholder: exactly one term, from a select shaped as the tree */
$single ??= false;
$labelId = 'terms-' . $taxonomy->name;
$invalid = $error !== null ? ' aria-invalid="true" aria-describedby="' . $this->e($labelId) . '-error"' : '';
?>
<?php if ($single): ?>
<div class="admin-field<?= $error !== null ? ' admin-field-invalid' : '' ?>">
    <label for="<?= $this->e($labelId) ?>"><?= $this->e($this->label($taxonomy->label)) ?></label>
    <select id="<?= $this->e($labelId) ?>" name="terms[<?= $this->e($taxonomy->name) ?>][]" required<?= $invalid ?>>
        <option value=""><?= $this->e($this->t('admin.entries.field.choose_term')) ?></option>
        <?php foreach ($nodes as $index => $node): ?>
        <?php // Entries belong to the leaves: a term whose next node is deeper has children and is shown, but disabled, unless it is the stored choice (a disabled option is not submitted). ?>
        <?php $isChosen = in_array((string) $node['term']->id, $chosen, true); ?>
        <?php $hasChildren = ($nodes[$index + 1]['depth'] ?? 0) > $node['depth']; ?>
        <option value="<?= $this->e($node['term']->id) ?>"<?= $isChosen ? ' selected' : '' ?><?= $hasChildren && !$isChosen ? ' disabled' : '' ?>><?= $this->e(str_repeat('— ', $node['depth']) . $node['term']->name) ?></option>
        <?php endforeach ?>
    </select>
    <?php if ($nodes === []): ?>
    <span class="admin-help"><?= $this->e($this->t('admin.entries.field.no_terms')) ?></span>
    <?php endif ?>
    <?php if ($error !== null): ?>
    <span class="admin-field-error" id="<?= $this->e($labelId) ?>-error"><?= $this->e($this->t($error)) ?></span>
    <?php endif ?>
</div>
<?php else: ?>
<div class="admin-field<?= $error !== null ? ' admin-field-invalid' : '' ?>" role="group" aria-labelledby="<?= $this->e($labelId) ?>">
    <span id="<?= $this->e($labelId) ?>" class="admin-field-label"><?= $this->e($this->label($taxonomy->label)) ?></span>
    <?php if ($nodes === []): ?>
    <span class="admin-help"><?= $this->e($this->t('admin.entries.field.no_terms')) ?></span>
    <?php endif ?>
    <?php foreach ($nodes as $node): ?>
    <label class="admin-check admin-depth-<?= $this->e(min($node['depth'], 5)) ?>"><input type="checkbox" name="terms[<?= $this->e($taxonomy->name) ?>][]" value="<?= $this->e($node['term']->id) ?>"<?= in_array((string) $node['term']->id, $chosen, true) ? ' checked' : '' ?><?= $invalid ?>> <?= $this->e($node['term']->name) ?></label>
    <?php endforeach ?>
    <?php if ($error !== null): ?>
    <span class="admin-field-error" id="<?= $this->e($labelId) ?>-error"><?= $this->e($this->t($error)) ?></span>
    <?php endif ?>
</div>
<?php endif ?>
