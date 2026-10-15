<?php
/**
 * The home page's slides (Field::slides('hero'), a list of AtarCms\Content\Field\Slide) as a carousel: each slide its
 * picture (the "hero" variant, on phones the taller crop "hero_phone"; the first loads at once) under a caption
 * with the title (h2: the page's h1 is the site name), the subtitle and the link: a button when the slide has a button
 * text, else the whole slide is the link.
 * Without JavaScript the first slide shows (site.css); with more than one slide js/slider.js (loaded by the layout for
 * a page with data-slider) shows them in turn every 6 seconds with arrows, dots and Pause, and keeps still under
 * prefers-reduced-motion or the accessibility toolbar's "Stop animations". One slide is a still picture: no controls.
 * A slide with a video (MP4/WebM of the media library) draws a muted, looping <video> with the picture as its poster
 * instead of the picture: slider.js plays the shown slide's video while motion is allowed (the markup never
 * starts it), so without JavaScript, under reduced motion or "Stop animations" the poster stays. A single video slide
 * loads the script too, with Pause as its only control. The first slide's picture loads at high priority: a picture
 * through fetchpriority, a video's poster through the layout's preload (collections/home/show.php passes it).
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Content\Field\Slide> $slides
 * @var AtarCms\Media\MediaSet $media
 */
$count = count($slides);
$moving = $count > 1;
// Each slide's video when it is one (a file of another type, or a deleted one, leaves the picture).
$videos = [];
foreach ($slides as $index => $slide) {
    $video = $slide->videoId === null ? null : $media->get($slide->videoId);
    if ($video !== null && $video->isVideo()) {
        $videos[$index] = $video;
    }
}
$scripted = $moving || $videos !== [];
?>
<section class="hero-slider" aria-roledescription="carousel" aria-label="<?= $this->e($this->t('site.slider.label')) ?>"<?= $scripted ? ' data-slider' : '' ?>>
    <div class="hero-slides" aria-live="off" data-slider-viewport>
        <?php foreach ($slides as $index => $slide): ?>
        <?php
        $image = $media->get($slide->imageId);
        if (isset($videos[$index])) {
            // Decorative motion: hidden from assistive technology, which reads the picture's alt text instead.
            $alt = $image?->alt($this->locale());
            $picture = '<video class="hero-slide-video" muted loop playsinline preload="metadata"'
                . ($image !== null ? ' poster="' . $this->e($this->mediaUrl($image, 'hero')) . '"' : '')
                . ' aria-hidden="true" data-slide-video><source src="' . $this->e($this->mediaUrl($videos[$index])) . '" type="' . $this->e($videos[$index]->mime) . '"></video>'
                . ($alt !== null ? "\n" . '            <span class="visually-hidden">' . $this->e($alt) . '</span>' : '');
        } else {
            // The first slide is the page's largest picture (its LCP): at once, at high priority, decoded as the browser
            // sees fit; the others wait.
            $picture = $this->img($image, 'hero', [
                'sizes' => '100vw',
                // Phones get a taller crop for their slide box (about 5:4, site.css) instead of the wide landscape one.
                'sources' => ['(max-width: 40rem)' => 'hero_phone'],
                'loading' => $index === 0 ? 'eager' : 'lazy',
                ...($index === 0 ? ['fetchpriority' => 'high', 'decoding' => 'auto'] : []),
            ]);
        }
        $linked = $slide->url !== null && $slide->button === null;
        $label = $this->t('site.slider.slide', ['number' => $index + 1, 'count' => $count]);
        ?>
        <div class="hero-slide<?= $index === 0 ? ' is-active' : '' ?>" role="group" aria-roledescription="slide" aria-label="<?= $this->e($label) ?>" data-slide>
            <?php if ($linked): ?>
            <?php // A slide with no texts would leave the link nameless when the picture has no alt text. ?>
            <a class="hero-slide-link" href="<?= $this->e($slide->url) ?>"<?= $slide->title === null && $slide->subtitle === null ? ' aria-label="' . $this->e($label) . '"' : '' ?>>
            <?php endif ?>
            <?= $picture ?>
            <?php if ($slide->title !== null || $slide->subtitle !== null || ($slide->url !== null && $slide->button !== null)): ?>
            <div class="hero-slide-caption">
                <div class="container">
                    <?php if ($slide->title !== null): ?>
                    <h2 class="hero-slide-title"><?= $this->e($slide->title) ?></h2>
                    <?php endif ?>
                    <?php if ($slide->subtitle !== null): ?>
                    <p class="hero-slide-subtitle"><?= $this->e($slide->subtitle) ?></p>
                    <?php endif ?>
                    <?php if ($slide->url !== null && $slide->button !== null): ?>
                    <a class="button hero-slide-button" href="<?= $this->e($slide->url) ?>"><?= $this->e($slide->button) ?></a>
                    <?php endif ?>
                </div>
            </div>
            <?php endif ?>
            <?php if ($linked): ?>
            </a>
            <?php endif ?>
        </div>
        <?php endforeach ?>
    </div>
    <?php if ($scripted): ?>
    <div class="hero-slider-controls">
        <div class="container hero-slider-bar">
            <?php if ($moving): ?>
            <button type="button" class="hero-slider-arrow hero-slider-previous" aria-label="<?= $this->e($this->t('site.slider.previous')) ?>" title="<?= $this->e($this->t('site.slider.previous')) ?>" data-slider-previous><?= $this->partial('partials/icon', ['name' => 'arrow-left']) ?></button>
            <div class="hero-slider-dots" role="group" aria-label="<?= $this->e($this->t('site.slider.dots')) ?>">
                <?php for ($index = 0; $index < $count; ++$index): ?>
                <button type="button" class="hero-slider-dot" aria-label="<?= $this->e($this->t('site.slider.go_to', ['number' => $index + 1])) ?>" title="<?= $this->e($this->t('site.slider.go_to', ['number' => $index + 1])) ?>"<?= $index === 0 ? ' aria-current="true"' : '' ?> data-slider-dot="<?= $this->e($index) ?>"></button>
                <?php endfor ?>
            </div>
            <button type="button" class="hero-slider-arrow hero-slider-next" aria-label="<?= $this->e($this->t('site.slider.next')) ?>" title="<?= $this->e($this->t('site.slider.next')) ?>" data-slider-next><?= $this->partial('partials/icon', ['name' => 'arrow-right']) ?></button>
            <?php endif ?>
            <?php $pauseLabel = $this->t('site.slider.pause'); $playLabel = $this->t('site.slider.play'); ?>
            <button type="button" class="hero-slider-pause" aria-label="<?= $this->e($pauseLabel) ?>" title="<?= $this->e($pauseLabel) ?>" data-slider-pause data-pause-label="<?= $this->e($pauseLabel) ?>" data-play-label="<?= $this->e($playLabel) ?>"><span class="hero-slider-pause-icon"><?= $this->partial('partials/icon', ['name' => 'pause']) ?></span><span class="hero-slider-play-icon"><?= $this->partial('partials/icon', ['name' => 'play']) ?></span></button>
        </div>
    </div>
    <?php endif ?>
</section>
