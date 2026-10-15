<?php
/**
 * The contacts page (plain): its text, the contact details and the Google map (all edited on this page's admin form)
 * and the contact form. The form needs no session: the page sets no cookie.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Entry|null $entry
 * @var AtarCms\Content\Collection $collection
 */
$heading = $entry?->title ?? $this->label($collection->label);
$this->layout('layouts/default', ['title' => $heading, 'wide' => true]);
$settings = $this->siteSettings();
$map = $this->contactMap();
$form = $this->contactForm();
?>
<article class="contacts container">
    <?= $this->partial('partials/breadcrumbs', ['items' => [[$heading, null]]]) ?>
    <header class="page-header">
        <h1><?= $this->e($heading) ?></h1>
    </header>
    <div class="contacts-grid">
        <div class="contacts-main">
            <?php if ($entry !== null && !$entry->field('body')->isEmpty()): ?>
            <div class="rich-text"><?= $entry->field('body')->html ?></div>
            <?php endif ?>
            <?php if ($form !== null): ?>
            <?= $this->partial('partials/contact-form', ['form' => $form]) ?>
            <?php endif ?>
        </div>
        <aside class="contacts-details" aria-label="<?= $this->e($this->t('site.aside.contacts')) ?>">
            <?= $this->partial('partials/contact-details', ['settings' => $settings]) ?>
            <?php if ($map !== null): ?>
            <?= $this->partial('partials/map', ['map' => $map]) ?>
            <?php endif ?>
        </aside>
    </div>
</article>
