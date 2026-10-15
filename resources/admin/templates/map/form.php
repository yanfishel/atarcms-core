<?php
/**
 * The map form as a full page (without JavaScript, or opened in a tab of its own): a card per way to enter the map,
 * each its own form with its "Save map"; a save goes back to the contact page's entry form. The refused way shows its
 * errors.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Collection $collection the contact page's collection
 * @var string $method the way entered last (its card shows the values)
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var string $back the entry form
 */
$title = $this->t('admin.map.title');
$action = $this->url('admin.map.save');
$this->layout('layouts/admin', [
    'title' => $title,
    'breadcrumbs' => [[$this->label($collection->label), $back], [$title]],
]);
?>
<?= $this->partial('partials/page-header', ['title' => $title]) ?>
<?php if ($errors !== []): ?>
<div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t('admin.form.fix_fields', ['count' => count($errors)])) ?></p></div>
<?php endif ?>
<div class="admin-map-ways">
    <?php foreach (AtarCms\Admin\Content\ContactMapController::METHODS as $tab): ?>
    <section class="admin-card" aria-labelledby="map-way-<?= $this->e($tab) ?>">
        <h2 id="map-way-<?= $this->e($tab) ?>"><?= $this->e($this->t('admin.map.tab.' . $tab)) ?></h2>
        <form class="admin-map-form" id="map-form-<?= $this->e($tab) ?>" method="post" action="<?= $this->e($action) ?>">
            <?= $this->csrfField() ?>
            <input type="hidden" name="method" value="<?= $this->e($tab) ?>">
            <?= $this->partial('map/fields', [
                'method' => $tab,
                'values' => $tab === $method ? $values : [],
                'errors' => $tab === $method ? $errors : [],
                'params' => $tab === $method ? $params : [],
                'autofocus' => false,
            ]) ?>
            <div class="admin-map-form-actions">
                <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($this->t('admin.map.save')) ?></button>
            </div>
        </form>
    </section>
    <?php endforeach ?>
</div>
<p><a class="admin-button admin-button-secondary" href="<?= $this->e($back) ?>"><?= $this->e($this->t('admin.map.cancel')) ?></a></p>
