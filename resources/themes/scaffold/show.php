<?php
/**
 * An entry of any collection without its own collections/{name}/show.php, and single() pages (the home page,
 * contacts) whose $entry is null until it is written in the admin. Prints the fields in config order: images, rich
 * text, plain text, tags; the contact form on the page of content.contact_form.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Collection $collection
 * @var AtarCms\Content\Entry|null $entry
 * @var AtarCms\Content\PageSeo|null $seo
 */
$heading = $entry?->title ?? $this->label($collection->label);
$this->layout('layouts/default', ['title' => $seo?->title ?? $heading, 'description' => $seo?->description, 'entry' => $entry]);
$media = $entry === null ? null : $this->mediaFor($entry);
$form = $collection->single ? $this->contactForm() : null;
?>
<article class="entry">
    <h1><?= $this->e($heading) ?></h1>
    <?php if ($entry !== null && $media !== null): ?>
    <?php if ($entry->publishedAt !== null && !$collection->single): ?>
    <p class="meta"><time datetime="<?= $this->e($this->date($entry->publishedAt, 'Y-m-d')) ?>"><?= $this->e($this->readableDate($entry->publishedAt, false)) ?></time></p>
    <?php endif ?>
    <?php foreach ($collection->fields as $name => $field): ?>
    <?php $type = $field->type->name(); ?>
    <?php if ($type === 'image'): ?>
    <?= $this->img($media->get($entry->field($name)), 'large', ['sizes' => '(max-width: 48rem) 100vw, 48rem']) ?>
    <?php elseif ($type === 'gallery'): ?>
    <?php foreach ($media->list($entry->field($name)) as $photo): ?>
    <?= $this->img($photo, 'card', ['sizes' => '(max-width: 40rem) 100vw, 20rem']) ?>
    <?php endforeach ?>
    <?php elseif ($type === 'richText'): ?>
    <?php $text = $entry->field($name); ?>
    <?php if ($text instanceof AtarCms\Content\Field\RichText && !$text->isEmpty()): ?>
    <div class="rich-text"><?= $text->html ?></div>
    <?php endif ?>
    <?php elseif ($type === 'text' || $type === 'textarea'): ?>
    <?php $text = $entry->field($name); ?>
    <?php if (is_string($text) && $text !== ''): ?>
    <p><?= nl2br($this->e($text)) ?></p>
    <?php endif ?>
    <?php elseif ($type === 'tags' && $field->taxonomy !== null): ?>
    <?php $tags = $this->terms($entry, $field->taxonomy); ?>
    <?php if ($tags !== []): ?>
    <ul class="tags">
        <?php foreach ($tags as $tag): ?>
        <li><a href="<?= $this->e((string) $this->termUrl($tag)) ?>">#<?= $this->e($tag->name) ?></a></li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
    <?php endif ?>
    <?php endforeach ?>
    <?php endif ?>
</article>
<?php if ($form !== null && $form->action === $this->url('content.' . $collection->name . '.show')): ?>
<?= $this->partial('partials/contact-form', ['form' => $form]) ?>
<?php endif ?>
