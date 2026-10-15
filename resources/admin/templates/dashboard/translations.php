<?php
/**
 * Translations (multilingual sites): per site language its flag (else its code) and "N of M", where M counts the
 * translation groups of the viewable translatable collections and N those with an entry in that language.
 *
 * @var AtarCms\View\Template $this
 * @var array{groups: int, locales: array<string, int>} $translations
 * @var bool $rtl
 */
$total = max(1, $translations['groups']);
?>
<section class="admin-card admin-dash-card" id="dashboard-translations" aria-labelledby="dashboard-translations-title">
    <div class="admin-dash-head">
        <h2 class="admin-dash-title" id="dashboard-translations-title"><?= $this->icon('languages') ?><?= $this->e($this->t('admin.dashboard.translations.title')) ?></h2>
    </div>
    <ul class="admin-dash-meters admin-tone-4">
        <?php foreach ($translations['locales'] as $code => $done): ?>
        <?php $flag = $this->languageFlag($code); $language = $this->languageName($code); ?>
        <li class="admin-dash-meter">
            <span class="admin-dash-meter-label">
                <?php if ($flag !== null): ?><img class="admin-flag" src="<?= $this->e($flag) ?>" alt="" width="20" height="20"><abbr class="admin-dash-meter-code" title="<?= $this->e($language) ?>"><?= $this->e(strtoupper($code)) ?></abbr><?php else: ?><abbr class="admin-flag-code" title="<?= $this->e($language) ?>"><?= $this->e(strtoupper($code)) ?></abbr><?php endif ?>
            </span>
            <span class="admin-dash-meter-value"><?= $this->e($this->t('admin.dashboard.translations.coverage', ['done' => $done, 'total' => $translations['groups']])) ?></span>
            <?= $this->partial('dashboard/meter', ['value' => $done, 'scale' => $total, 'rtl' => $rtl]) ?>
        </li>
        <?php endforeach ?>
    </ul>
</section>
