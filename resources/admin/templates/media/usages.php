<?php
/** @var AtarCms\View\Template $this */
/** @var list<array{title: string|null, collection: string|null, locale: string|null, field: string, url: string|null}> $usages */
/** @var array{url: string|null, uses: list<string>}|null $settings the site settings use the file (logo, icon, sharing image) */
$settings ??= null;
?>
<?php if ($usages === [] && $settings === null): ?>
<p class="admin-muted"><?= $this->e($this->t('admin.media.usage.none')) ?></p>
<?php else: ?>
<ul class="admin-media-usages">
    <?php foreach ($settings['uses'] ?? [] as $use): ?>
    <li>
        <?php if ($settings['url'] !== null): ?>
        <a href="<?= $this->e($settings['url']) ?>"><?= $this->e($this->t('admin.media.usage.settings')) ?></a>
        <?php else: ?>
        <span><?= $this->e($this->t('admin.media.usage.settings')) ?></span>
        <?php endif ?>
        <span class="admin-muted">— <?= $this->e($this->t(match ($use) {
            AtarCms\Settings\SiteSettings::USE_LOGO => 'admin.media.usage.logo',
            AtarCms\Settings\SiteSettings::USE_ICON => 'admin.media.usage.icon',
            default => 'admin.media.usage.seo',
        })) ?></span>
    </li>
    <?php endforeach ?>
    <?php foreach ($usages as $usage): ?>
    <li>
        <?php if ($usage['url'] !== null && $usage['title'] !== null): ?>
        <a href="<?= $this->e($usage['url']) ?>"><?= $this->e($usage['title']) ?></a>
        <span class="admin-muted">· <?= $this->e($this->label((string) $usage['collection'])) ?> · <?= $this->e(strtoupper((string) $usage['locale'])) ?></span>
        <?php else: ?>
        <span class="admin-muted"><?= $this->e($this->t('admin.media.usage.hidden')) ?></span>
        <?php endif ?>
        <span class="admin-muted">— <?= $this->e($this->label($usage['field'])) ?></span>
    </li>
    <?php endforeach ?>
</ul>
<?php endif ?>
