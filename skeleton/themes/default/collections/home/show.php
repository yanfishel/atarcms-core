<?php
/**
 * The home page (plain): the slides of the home page's form as a slider (partials/hero-slider.php), the hero (the site
 * name, the lead line, a button to the contacts page), a block of the latest articles of each top section, the latest
 * news, then the home page's text. Its <title> is the site settings' home page title (else the site name); its SEO
 * comes from the site settings.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Entry|null $entry
 * @var AtarCms\Content\Collection $collection
 */
$siteName = $this->siteName();
$lead = $entry !== null ? $entry->field('lead') : null;
$slides = $entry !== null ? $entry->field('hero') : [];
$heroMedia = $entry !== null ? $this->mediaFor($entry) : null;
// The first slide is the page's largest picture (its LCP). A picture slide loads it at high priority itself; a video
// slide's poster is fetched late and at low priority, so the layout preloads it.
$first = $slides[0] ?? null;
$firstVideo = $first?->videoId !== null ? $heroMedia?->get($first->videoId) : null;
$firstPoster = $first !== null && $firstVideo?->isVideo() === true ? $heroMedia?->get($first->imageId) : null;
$this->layout('layouts/default', [
    'title' => null,
    'fullTitle' => $this->siteSettings()->metaTitle,
    'wide' => true,
    'preloadImage' => $firstPoster !== null ? $this->mediaUrl($firstPoster, 'hero') : null,
]);
$sections = $this->termChildren('sections');
$news = $this->entries('news')->latest(3);
$newsMedia = $this->mediaFor($news);
?>
<?php if ($entry !== null && $slides !== []): ?>
<?= $this->partial('partials/hero-slider', ['slides' => $slides, 'media' => $heroMedia]) ?>
<?php endif ?>
<section class="hero">
    <div class="container">
        <div>
            <h1><?= $this->e($siteName) ?></h1>
            <?php if (is_string($lead) && $lead !== ''): ?>
            <p class="hero-lead"><?= $this->e($lead) ?></p>
            <?php endif ?>
            <div class="hero-actions">
                <a class="button" href="<?= $this->e($this->url('content.contacts.show')) ?>#contact"><?= $this->e($this->t('site.hero.action')) ?></a>
                <?php if ($news !== []): ?>
                <a class="button button-ghost" href="<?= $this->e($this->url('content.news.index')) ?>"><?= $this->e($this->t('site.all_news')) ?></a>
                <?php endif ?>
            </div>
        </div>
    </div>
</section>
<?php foreach ($sections as $section): ?>
<?php $articles = $this->entries('articles')->withinTerm('sections', $section)->latest(3); ?>
<?php if ($articles !== []): ?>
<?php $urls = $this->entryUrls($articles); ?>
<?php $media = $this->mediaFor($articles); ?>
<section class="block container" aria-labelledby="section-<?= $this->e($section->id) ?>">
    <header class="block-header">
        <h2 id="section-<?= $this->e($section->id) ?>"><?= $this->e($section->name) ?></h2>
        <a href="<?= $this->e($this->termUrl($section)) ?>"><?= $this->e($this->t('site.section_more', ['section' => $section->name])) ?></a>
    </header>
    <ul class="cards">
        <?php foreach ($articles as $item): ?>
        <li><?= $this->partial('partials/card', ['item' => $item, 'url' => $urls[$item->id] ?? '#', 'media' => $media, 'date' => false]) ?></li>
        <?php endforeach ?>
    </ul>
</section>
<?php endif ?>
<?php endforeach ?>
<?php if ($news !== []): ?>
<?php $tags = $this->termsFor($news, 'tags'); ?>
<section class="block container" aria-labelledby="latest-news">
    <header class="block-header">
        <h2 id="latest-news"><?= $this->e($this->t('site.latest_news')) ?></h2>
        <a href="<?= $this->e($this->url('content.news.index')) ?>"><?= $this->e($this->t('site.all_news')) ?></a>
    </header>
    <ul class="cards">
        <?php foreach ($news as $item): ?>
        <li><?= $this->partial('partials/card', ['item' => $item, 'url' => $this->entryUrl($item), 'media' => $newsMedia, 'date' => true, 'tags' => $tags[$item->id] ?? []]) ?></li>
        <?php endforeach ?>
    </ul>
</section>
<?php endif ?>
<?php if ($entry !== null && !$entry->field('intro')->isEmpty()): ?>
<section class="container intro">
    <div class="page-narrow rich-text"><?= $entry->field('intro')->html ?></div>
</section>
<?php endif ?>
