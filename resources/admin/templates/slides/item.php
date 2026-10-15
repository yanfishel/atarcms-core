<?php
/**
 * One item of the slides widget (fields/slides.php): the compact row slides.js shows (under .js) and the fieldset of
 * the slide's inputs, which is the whole widget without JavaScript. The inputs are named
 * fields[{field}][{index}][image|video|title|subtitle|url|button|position|delete] and marked data-slides-input for
 * slides.js. A blank item (the no-JS "new slide") and a new one (the template) have no delete box. A slide with a video
 * shows a video marker on its row (hidden otherwise; slides.js shows and names it).
 *
 * @var AtarCms\View\Template $this
 * @var string $name
 * @var string $id the widget's id
 * @var int|string $index
 * @var array{image: string, video: string, title: string, subtitle: string, url: string, button: string} $row
 * @var AtarCms\Media\Media|null $media the slide's image when it exists
 * @var AtarCms\Media\Media|null $video the slide's video when it exists
 * @var string $legend
 * @var bool $blank
 * @var bool $new
 * @var string $lang
 */
$input = static fn(string $part): string => $name . '[' . $index . '][' . $part . ']';
$inputId = static fn(string $part): string => $id . '-' . $index . '-' . $part;
// The video's name (its number when the file is gone or the id is not one yet): the marker's label.
$videoName = $row['video'] === '' ? '' : ($video?->originalName ?? '#' . $row['video']);
$videoLabel = $videoName === '' ? '' : $this->t('admin.slides.has_video', ['name' => $videoName]);
?>
<li class="admin-slides-item<?= $blank ? ' admin-slides-blank' : '' ?>" data-slides-item<?= $blank ? ' data-slides-blank' : '' ?> data-file="<?= $this->e($media?->originalName ?? '') ?>" data-video-file="<?= $this->e($video?->originalName ?? '') ?>" data-video-url="<?= $this->e($video === null ? '' : $this->mediaUrl($video)) ?>">
    <div class="admin-slides-row">
        <span class="admin-slides-handle" data-slides-handle title="<?= $this->e($this->t('admin.slides.drag')) ?>"><?= $this->icon('grip-vertical') ?></span>
        <span class="admin-slides-preview" data-slides-preview><?php if ($media !== null): ?><?= trim($this->partial('media/thumb', ['media' => $media])) ?><?php endif ?></span>
        <span class="admin-slides-video" data-slides-video role="img" title="<?= $this->e($videoLabel) ?>" aria-label="<?= $this->e($videoLabel) ?>"<?= $videoLabel === '' ? ' hidden' : '' ?>><?= $this->icon('video') ?></span>
        <span class="admin-slides-text">
            <span class="admin-slides-title" data-slides-title><?= $this->e($row['title'] !== '' ? $row['title'] : $this->t('admin.slides.untitled')) ?></span>
            <span class="admin-slides-subtitle" data-slides-subtitle><?= $this->e($row['subtitle']) ?></span>
        </span>
        <span class="admin-slides-controls">
            <button type="button" class="admin-slides-action" data-slides-up title="<?= $this->e($this->t('admin.slides.up')) ?>" aria-label="<?= $this->e($this->t('admin.slides.up')) ?>"><?= $this->icon('arrow-up') ?></button>
            <button type="button" class="admin-slides-action" data-slides-down title="<?= $this->e($this->t('admin.slides.down')) ?>" aria-label="<?= $this->e($this->t('admin.slides.down')) ?>"><?= $this->icon('arrow-down') ?></button>
            <button type="button" class="admin-slides-action" data-slides-edit title="<?= $this->e($this->t('admin.slides.edit')) ?>" aria-label="<?= $this->e($this->t('admin.slides.edit')) ?>"><?= $this->icon('pencil') ?></button>
            <button type="button" class="admin-slides-action admin-slides-delete" data-slides-delete title="<?= $this->e($this->t('admin.slides.delete')) ?>" aria-label="<?= $this->e($this->t('admin.slides.delete')) ?>"><?= $this->icon('trash-2') ?></button>
        </span>
    </div>
    <fieldset class="admin-slides-fieldset">
        <legend><?= $this->e($legend) ?></legend>
        <?php if ($media !== null): ?>
        <?= $this->partial('media/thumb', ['media' => $media]) ?>
        <?php endif ?>
        <div class="admin-field">
            <label for="<?= $this->e($inputId('image')) ?>"><?= $this->e($this->t('admin.slides.image_id')) ?></label>
            <input id="<?= $this->e($inputId('image')) ?>" name="<?= $this->e($input('image')) ?>" inputmode="numeric" dir="ltr" autocomplete="off" value="<?= $this->e($row['image']) ?>" data-slides-input="image">
            <span class="admin-help"><?= $this->e($this->t('admin.slides.image_id_help')) ?></span>
        </div>
        <div class="admin-field">
            <label for="<?= $this->e($inputId('video')) ?>"><?= $this->e($this->t('admin.slides.video_id')) ?></label>
            <input id="<?= $this->e($inputId('video')) ?>" name="<?= $this->e($input('video')) ?>" inputmode="numeric" dir="ltr" autocomplete="off" value="<?= $this->e($row['video']) ?>" data-slides-input="video">
            <span class="admin-help"><?= $this->e($this->t('admin.slides.video_id_help')) ?></span>
        </div>
        <div class="admin-field">
            <label for="<?= $this->e($inputId('title')) ?>"><?= $this->e($this->t('admin.slides.title')) ?></label>
            <input id="<?= $this->e($inputId('title')) ?>" name="<?= $this->e($input('title')) ?>" maxlength="255" value="<?= $this->e($row['title']) ?>" data-slides-input="title"<?= $lang ?>>
        </div>
        <div class="admin-field">
            <label for="<?= $this->e($inputId('subtitle')) ?>"><?= $this->e($this->t('admin.slides.subtitle')) ?></label>
            <input id="<?= $this->e($inputId('subtitle')) ?>" name="<?= $this->e($input('subtitle')) ?>" maxlength="500" value="<?= $this->e($row['subtitle']) ?>" data-slides-input="subtitle"<?= $lang ?>>
        </div>
        <div class="admin-field">
            <label for="<?= $this->e($inputId('url')) ?>"><?= $this->e($this->t('admin.slides.url')) ?></label>
            <input id="<?= $this->e($inputId('url')) ?>" name="<?= $this->e($input('url')) ?>" maxlength="255" dir="ltr" value="<?= $this->e($row['url']) ?>" data-slides-input="url">
        </div>
        <div class="admin-field">
            <label for="<?= $this->e($inputId('button')) ?>"><?= $this->e($this->t('admin.slides.button')) ?></label>
            <input id="<?= $this->e($inputId('button')) ?>" name="<?= $this->e($input('button')) ?>" maxlength="100" value="<?= $this->e($row['button']) ?>" data-slides-input="button"<?= $lang ?>>
        </div>
        <div class="admin-field admin-slides-position">
            <label for="<?= $this->e($inputId('position')) ?>"><?= $this->e($this->t('admin.slides.position')) ?></label>
            <input type="number" id="<?= $this->e($inputId('position')) ?>" name="<?= $this->e($input('position')) ?>" step="1" value="<?= $blank || $new ? '' : $this->e((int) $index + 1) ?>" data-slides-input="position">
        </div>
        <?php if (!$blank && !$new): ?>
        <label class="admin-check"><input type="checkbox" name="<?= $this->e($input('delete')) ?>" value="1"> <?= $this->e($this->t('admin.slides.remove')) ?></label>
        <?php endif ?>
    </fieldset>
</li>
