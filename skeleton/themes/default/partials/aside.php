<?php
/**
 * The side column of inner pages, in boxes, each only when it has something to show:
 * - $section (a Term of the sections): the subsections of its top-level section, the current one ($section) marked;
 * - $more (a Term): the latest articles of that section, without $exclude (an entry id);
 * - $news: the latest news, without $exclude;
 * - $tags: the tags of the news;
 * - $contacts: the phone, email and address of the site settings with a link to the contacts page.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Term|null $section
 * @var AtarCms\Content\Term|null $more
 * @var int|null $exclude
 * @var bool $news
 * @var bool $tags
 * @var bool $contacts
 */
$exclude ??= null;
$section ??= null;
$more ??= null;
?>
<?php if ($section !== null): ?>
<?php $trail = $this->termTrail($section); ?>
<?php $top = $trail[0] ?? $section; ?>
<?php $children = $this->termChildren('sections', $top); ?>
<?php if ($children !== []): ?>
<nav class="aside-box" aria-labelledby="aside-sections">
    <h2 id="aside-sections"><?= $this->e($top->name) ?></h2>
    <ul class="aside-links">
        <?php foreach ($children as $child): ?>
        <?php $isCurrent = in_array($child->id, array_map(static fn(AtarCms\Content\Term $t): int => $t->id, $trail), true); ?>
        <li><a href="<?= $this->e($this->termUrl($child)) ?>"<?= $isCurrent ? ' aria-current="' . ($child->id === $section->id ? 'page' : 'true') . '"' : '' ?>><?= $this->e($child->name) ?></a></li>
        <?php endforeach ?>
    </ul>
</nav>
<?php endif ?>
<?php endif ?>
<?php if ($more !== null): ?>
<?php $articles = array_values(array_filter($this->entries('articles')->withTerm('sections', $more)->latest(5), static fn(AtarCms\Content\Entry $e): bool => $e->id !== $exclude)); ?>
<?php if ($articles !== []): ?>
<?php $urls = $this->entryUrls($articles); ?>
<section class="aside-box" aria-labelledby="aside-more">
    <h2 id="aside-more"><?= $this->e($this->t('site.aside.more')) ?></h2>
    <ul class="aside-links">
        <?php foreach (array_slice($articles, 0, 4) as $article): ?>
        <li><a href="<?= $this->e($urls[$article->id] ?? '#') ?>"><?= $this->e($article->title) ?></a></li>
        <?php endforeach ?>
    </ul>
</section>
<?php endif ?>
<?php endif ?>
<?php if ($news ?? false): ?>
<?php $latest = array_values(array_filter($this->entries('news')->latest(5), static fn(AtarCms\Content\Entry $e): bool => $e->id !== $exclude)); ?>
<?php if ($latest !== []): ?>
<section class="aside-box" aria-labelledby="aside-news">
    <h2 id="aside-news"><?= $this->e($this->t('site.aside.latest_news')) ?></h2>
    <ul class="aside-links">
        <?php foreach (array_slice($latest, 0, 4) as $item): ?>
        <li><a href="<?= $this->e($this->entryUrl($item)) ?>"><?= $this->e($item->title) ?><small><?= $this->e($this->readableDate($item->publishedAt, false)) ?></small></a></li>
        <?php endforeach ?>
    </ul>
    <p class="read-more"><a href="<?= $this->e($this->url('content.news.index')) ?>"><?= $this->e($this->t('site.all_news')) ?></a></p>
</section>
<?php endif ?>
<?php endif ?>
<?php if ($tags ?? false): ?>
<?php $allTags = array_slice($this->termChildren('tags'), 0, 20); ?>
<?php if ($allTags !== []): ?>
<section class="aside-box" aria-labelledby="aside-tags">
    <h2 id="aside-tags"><?= $this->e($this->t('site.aside.tags')) ?></h2>
    <ul class="tags">
        <?php foreach ($allTags as $tag): ?>
        <li><a class="tag" href="<?= $this->e($this->termUrl($tag)) ?>">#<?= $this->e($tag->name) ?></a></li>
        <?php endforeach ?>
    </ul>
</section>
<?php endif ?>
<?php endif ?>
<?php if ($contacts ?? false): ?>
<?php $settings = $this->siteSettings(); ?>
<?php if ($settings->phone !== null || $settings->email !== null || $settings->address !== null): ?>
<section class="aside-box" aria-labelledby="aside-contacts">
    <h2 id="aside-contacts"><?= $this->e($this->t('site.aside.contacts')) ?></h2>
    <?= $this->partial('partials/contact-details', ['settings' => $settings, 'messengers' => false]) ?>
    <p class="read-more"><a href="<?= $this->e($this->url('content.contacts.show')) ?>#contact"><?= $this->e($this->t('site.contacts.write')) ?></a></p>
</section>
<?php endif ?>
<?php endif ?>
