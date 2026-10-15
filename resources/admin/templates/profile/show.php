<?php
/**
 * The Profile page (/admin/profile), in the entry form's two columns: the side column holds a read-only card of who is
 * signed in (the avatar with the role dot, the name, the e-mail, the role chip and the last sign-in; first in the
 * markup, so it comes first when the columns stack), the main column one form with one tab "Interface" (tabs.js;
 * without JavaScript the panel shows under its heading): the interface language (the media dropdown, media.js, of the
 * admin catalogs, each with the theme's round flag and named in itself) and the admin colour (the same dropdown of
 * swatches; first the site colour, stored as none), saved together by POST /admin/profile. Both dropdowns open and
 * work without JavaScript. The content language is switched in the top bar; in the linked mode (linkedLanguage(),
 * Plan 37) the top bar switches the interface too, so the tab holds only the admin colour.
 *
 * @var AtarCms\View\Template $this
 * @var list<string> $languages the interface languages
 * @var string $current the chosen interface language
 * @var string $accent the chosen admin colour, '' for the site colour
 * @var AtarCms\Settings\AdminAccent $siteAccent the site colour (the preset nearest to the theme colour)
 * @var array<string, string> $errors field => translation key
 */
$user = $this->user();
$title = $this->t('admin.profile.title');
$tab = $this->t('admin.profile.tab.interface');
$count = count($errors);
$accentLabel = $this->t('admin.profile.accent');
$choices = [['value' => '', 'label' => $this->t('admin.profile.accent_site', ['color' => $this->t('admin.accent.' . $siteAccent->value)]), 'icon' => null, 'swatch' => $siteAccent->value]];
foreach (AtarCms\Settings\AdminAccent::cases() as $preset) {
    $choices[] = ['value' => $preset->value, 'label' => $this->t('admin.accent.' . $preset->value), 'icon' => null, 'swatch' => $preset->value];
}
$languageLabel = $this->t('admin.profile.language');
$languageChoices = [];
foreach ($languages as $code) {
    $flag = $this->languageFlag($code);
    $choice = ['value' => $code, 'label' => $this->languageName($code), 'icon' => 'languages', 'attrs' => $this->langAttrs($code)];
    $languageChoices[] = $flag === null ? $choice : $choice + ['flag' => $flag];
}
$localeInvalid = isset($errors['locale']);
$accentInvalid = isset($errors['accent']);
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$title]], 'scripts' => ['tabs.js', 'media.js']]);
?>
<?= $this->partial('partials/page-header', ['title' => $title]) ?>
<?php if ($count > 0): ?>
<div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t('admin.form.fix_fields', ['count' => $count])) ?></p></div>
<?php endif ?>
<div class="admin-entry-form">
<div class="admin-form-layout admin-profile-layout">
<?php if ($user !== null): ?>
<section class="admin-card admin-profile-card" aria-labelledby="profile-name">
    <span class="admin-avatar admin-avatar-large admin-role admin-role-<?= $this->e($user->role) ?>" aria-hidden="true"><?php if ($user->avatarVersion !== null): ?><img class="admin-avatar-image" src="<?= $this->e($this->url('admin.avatar', ['id' => (string) $user->id]) . '?v=' . $user->avatarVersion) ?>" alt="" width="72" height="72"><?php else: ?><?= $this->e($user->initials()) ?><?php endif ?></span>
    <div class="admin-profile-facts">
        <h2 class="admin-profile-name" id="profile-name"><?= $this->e($user->displayName()) ?></h2>
        <p class="admin-user-meta"><?= $this->e($user->email) ?></p>
        <p><span class="admin-chip admin-role admin-role-<?= $this->e($user->role) ?>"><?= $this->e($this->label($this->roleLabel($user->role))) ?></span></p>
        <p class="admin-user-meta"><?= $this->e($user->lastLoginAt === null ? $this->t('admin.profile.never') : $this->t('admin.profile.last_login', ['date' => $this->readableDate($user->lastLoginAt)])) ?></p>
    </div>
</section>
<?php endif ?>
<form class="admin-profile-form" method="post" action="<?= $this->e($this->url('admin.profile.save')) ?>">
    <?= $this->csrfField() ?>
    <div class="admin-form-column" data-tabs="">
        <div class="admin-tab-list" role="tablist" aria-label="<?= $this->e($this->t('admin.profile.tabs')) ?>">
            <button type="button" class="admin-tab" role="tab" id="tab-interface" aria-controls="panel-interface" aria-selected="true" tabindex="0" data-tab="interface" data-errors="<?= $this->e($count) ?>">
                <?= $this->e($tab) ?>
                <?php if ($count > 0): ?>
                <span class="admin-tab-badge" aria-hidden="true"><?= $this->e($count) ?></span><span class="admin-visually-hidden"><?= $this->e($this->t('admin.form.tab_errors', ['count' => $count])) ?></span>
                <?php endif ?>
            </button>
        </div>
        <section class="admin-tab-panel admin-card admin-tab-panel-open" role="tabpanel" id="panel-interface" aria-labelledby="tab-interface">
            <h2 class="admin-tab-heading"><?= $this->e($tab) ?></h2>
            <?php if (!$this->linkedLanguage()): ?>
            <?php // The linked mode has no interface language of its own: the top bar's language is the admin's (Plan 37). ?>
            <div class="admin-field<?= $localeInvalid ? ' admin-field-invalid' : '' ?>">
                <span class="admin-field-label" id="locale-label"><?= $this->icon('languages') ?><?= $this->e($languageLabel) ?></span>
                <?= $this->partial('media/dropdown', ['name' => 'locale', 'label' => $languageLabel, 'options' => $languageChoices, 'current' => $current, 'show' => 'both', 'aria' => $localeInvalid ? ' aria-invalid="true" aria-describedby="locale-help locale-error"' : ' aria-describedby="locale-help"']) ?>
                <span class="admin-help" id="locale-help"><?= $this->e($this->t('admin.profile.language_help')) ?></span>
                <?php if ($localeInvalid): ?>
                <span class="admin-field-error" id="locale-error"><?= $this->e($this->t($errors['locale'])) ?></span>
                <?php endif ?>
            </div>
            <?php endif ?>
            <div class="admin-field<?= $accentInvalid ? ' admin-field-invalid' : '' ?>">
                <span class="admin-field-label" id="accent-label"><?= $this->icon('palette') ?><?= $this->e($accentLabel) ?></span>
                <?= $this->partial('media/dropdown', ['name' => 'accent', 'label' => $accentLabel, 'options' => $choices, 'current' => $accent, 'show' => 'both', 'aria' => $accentInvalid ? ' aria-invalid="true" aria-describedby="accent-help accent-error"' : ' aria-describedby="accent-help"']) ?>
                <span class="admin-help" id="accent-help"><?= $this->e($this->t('admin.profile.accent_help')) ?></span>
                <?php if ($accentInvalid): ?>
                <span class="admin-field-error" id="accent-error"><?= $this->e($this->t($errors['accent'])) ?></span>
                <?php endif ?>
            </div>
        </section>
    </div>
    <div class="admin-action-bar">
        <p class="admin-action-bar-status"></p>
        <div class="admin-action-bar-buttons">
            <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($this->t('admin.profile.save')) ?></button>
        </div>
    </div>
</form>
</div>
</div>
