<?php
/**
 * The cookie banner of the analytics counters and advertising pixels (Site settings → Integrations; nothing without a
 * service, nothing for signed-in users). The core script (consentHead(), /admin-assets/consent.js) drives it through
 * its data attributes: it shows the banner (hidden) while the visitor has not decided every configured category,
 * loads each category after it is allowed, remembers the choice in the atar_consent cookie, and the footer's "Cookie
 * settings" button (data-consent-open) reopens it with the focus on it. With statistics only it offers Accept and
 * Decline (Plan 40); with advertising pixels Accept all, Decline and Choose, which opens the panel of the categories
 * (a switch per configured category with its services, then Save choice; the script shows the stored choice there).
 * A region, not a modal: it traps nothing and the page stays readable around it. Without JavaScript nothing loads and
 * nothing shows.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Analytics\ConsentView $consent
 */
// The services by name (the theme's catalog), in comma lists (Hebrew keeps Latin names apart that way).
$names = fn(array $services): string => implode(', ', array_map(fn(string $service): string => $this->t('site.consent.services.' . $service), $services));
$statistics = $names($consent->statistics);
$advertising = $names($consent->advertising);
$choice = $consent->advertising !== [];
if (!$choice) {
    // Statistics only: Plan 40's banner, one or both counters ("both" joins them in a sentence).
    $services = count($consent->statistics) > 1 ? $this->t('site.consent.services.both') : $statistics;
    $title = $this->t('site.consent.title');
    $default = $consent->webvisor
        ? $this->t('site.consent.text_replay', ['services' => $services])
        : $this->t('site.consent.text', ['services' => $services]);
} elseif ($consent->statistics === []) {
    $title = $this->t('site.consent.title_ads_only');
    $default = $this->t('site.consent.text_ads_only', ['advertising' => $advertising]);
} else {
    $title = $this->t('site.consent.title_ads');
    $default = $this->t($consent->webvisor ? 'site.consent.text_ads_replay' : 'site.consent.text_ads', ['statistics' => $statistics, 'advertising' => $advertising]);
}
// The owner's text of this language, else the theme's, which names session replay when Webvisor records.
$text = $consent->text ?? $default;
$categories = array_filter([
    'statistics' => $consent->statistics === [] ? null : $this->t($consent->webvisor ? 'site.consent.category.statistics_text_replay' : 'site.consent.category.statistics_text', ['services' => $statistics]),
    'advertising' => $choice ? $this->t('site.consent.category.advertising_text', ['services' => $advertising]) : null,
]);
?>
<section class="consent" id="consent" aria-labelledby="consent-title" tabindex="-1" hidden data-consent>
    <h2 id="consent-title"><?= $this->e($title) ?></h2>
    <p class="consent-text"><?= $this->e($text) ?></p>
    <?php if ($consent->privacyUrl !== null): ?>
    <p class="consent-links"><a href="<?= $this->e($consent->privacyUrl) ?>"><?= $this->e($this->t('site.consent.privacy')) ?></a></p>
    <?php endif ?>
    <div class="consent-actions">
        <?php if ($choice): ?>
        <button type="button" class="consent-button" data-consent-accept><?= $this->e($this->t('site.consent.accept_all')) ?></button>
        <button type="button" class="consent-button" data-consent-decline><?= $this->e($this->t('site.consent.decline')) ?></button>
        <button type="button" class="consent-button" aria-expanded="false" aria-controls="consent-choice" data-consent-choose><?= $this->e($this->t('site.consent.choose')) ?></button>
        <?php else: ?>
        <button type="button" class="consent-button" data-consent-accept><?= $this->e($this->t('site.consent.accept')) ?></button>
        <button type="button" class="consent-button" data-consent-decline><?= $this->e($this->t('site.consent.decline')) ?></button>
        <?php endif ?>
    </div>
    <?php if ($choice): ?>
    <div class="consent-choice" id="consent-choice" hidden data-consent-panel>
        <fieldset class="consent-categories">
            <legend><?= $this->e($this->t('site.consent.choose_title')) ?></legend>
            <?php foreach ($categories as $category => $description): ?>
            <div class="consent-category">
                <input type="checkbox" role="switch" id="consent-<?= $this->e($category) ?>" aria-describedby="consent-<?= $this->e($category) ?>-text" data-consent-category="<?= $this->e($category) ?>">
                <label for="consent-<?= $this->e($category) ?>"><?= $this->e($this->t('site.consent.category.' . $category)) ?></label>
                <p class="consent-category-text" id="consent-<?= $this->e($category) ?>-text"><?= $this->e($description) ?></p>
            </div>
            <?php endforeach ?>
        </fieldset>
        <button type="button" class="consent-button consent-save" data-consent-save><?= $this->e($this->t('site.consent.save')) ?></button>
    </div>
    <?php endif ?>
</section>
