<?php
/**
 * A YouTube field: the input (an id or a link; the server stores the id) and the card of the video it names, rendered
 * from the server output (hidden without a video): the cover from YouTube's image host (the form's policy allows it)
 * with Play over it, a link to the video on YouTube and Remove (the input shows the id). youtube.js keeps the card equal to the input,
 * builds the addresses from the data attributes and plays the video in place of the cover; Play and Remove show only
 * with JavaScript (admin.css).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Field\Field $field
 * @var string $name
 * @var string $id
 * @var mixed $value
 * @var string $aria
 */
$video = is_string($value) ? AtarCms\Content\Field\YouTubeVideo::parse($value) : null;
$play = $this->t('admin.entries.field.youtube_play');
?>
<input type="text" inputmode="url" dir="ltr" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e(is_string($value) ? $value : '') ?>"<?= $field->required ? ' required' : '' ?><?= $aria ?>>
<span class="admin-help"><?= $this->e($this->t('admin.entries.field.youtube_help')) ?></span>
<div class="admin-youtube" data-youtube data-youtube-input="<?= $this->e($id) ?>" data-youtube-embed="<?= $this->e(AtarCms\Content\Field\VideoUrl::YOUTUBE_EMBED) ?>" data-youtube-thumb="<?= $this->e(AtarCms\Content\Field\YouTubeVideo::THUMBNAIL) ?>" data-youtube-watch="<?= $this->e(AtarCms\Content\Field\YouTubeVideo::WATCH_BASE) ?>" data-youtube-id="<?= $this->e($video?->id ?? '') ?>"<?= $video === null ? ' hidden' : '' ?>>
    <div class="admin-youtube-preview" data-youtube-preview><img class="admin-youtube-thumb"<?= $video !== null ? ' src="' . $this->e($video->thumbnailUrl()) . '"' : '' ?> alt="" data-youtube-image><button type="button" class="admin-youtube-play" aria-label="<?= $this->e($play) ?>" title="<?= $this->e($play) ?>" data-youtube-play><?= $this->icon('play') ?></button></div>
    <div class="admin-youtube-meta">
        <a class="admin-youtube-open" href="<?= $this->e($video?->watchUrl() ?? '') ?>" target="_blank" rel="noopener noreferrer" data-youtube-open><?= $this->icon('external-link') ?><?= $this->e($this->t('admin.entries.field.youtube_open')) ?></a>
        <button type="button" class="admin-button admin-button-link admin-button-danger admin-button-compact admin-youtube-remove" data-youtube-remove><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.entries.field.youtube_remove')) ?></button>
    </div>
    <template data-youtube-player><iframe class="admin-youtube-frame" title="<?= $this->e($this->t('admin.entries.field.youtube_player')) ?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin"></iframe></template>
    <template data-youtube-invalid><?= trim($this->partial('partials/toast', ['type' => 'error', 'text' => $this->t('validation.field.youtube')])) ?></template>
</div>
