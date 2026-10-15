<?php
/**
 * A dropdown of the media toolbar (view, order, type, search scope): a <details> whose summary shows the current
 * choice and whose panel holds radio options of the filter form, each with its check mark. It opens and works without
 * JavaScript (pick, then the form's submit button); media.js copies the chosen option into the summary, closes the
 * panel and submits the form. Outside a filter form (the Profile's admin colour, the settings' toolbar corner and
 * theme colour presets) media.js only shows the choice and closes the panel; an option's swatch (an admin colour
 * preset) is shown instead of an icon. With a placeholder and no option matching the current value, the summary shows
 * the placeholder and no option is checked (the theme colour presets beside a custom colour).
 *
 * @var AtarCms\View\Template $this
 * @var string $name the form field
 * @var string $label what is chosen ("Order"), the summary's name with the current choice
 * @var list<array{value: string, label: string, icon: string|null, swatch?: string, flag?: string, attrs?: string}> $options
 *      an option's swatch is an admin colour preset and its flag a round flag image (the interface languages), each
 *      shown instead of an icon; attrs (escaped attributes, the language's lang and dir) go on its text
 * @var string $aria (optional) attributes for every radio (aria-invalid and aria-describedby of a field error)
 * @var string $current the current value
 * @var string $show what the summary shows: "icon", "text" or "both"
 * @var string|null $placeholder (optional) the summary's text while no option matches $current
 */
$aria ??= '';
$placeholder ??= null;
$mark = function (array $option): string {
    if (isset($option['flag'])) {
        return '<span class="admin-media-option-icon" data-option-icon><img class="admin-locale-flag" src="' . $this->e($option['flag']) . '" alt="" width="20" height="20"></span>';
    }
    if (isset($option['swatch'])) {
        return '<span class="admin-media-option-icon" data-option-icon><span class="admin-accent-swatch admin-accent-swatch-' . $this->e($option['swatch']) . '"></span></span>';
    }

    return $option['icon'] === null ? '' : '<span class="admin-media-option-icon" data-option-icon>' . $this->icon($option['icon']) . '</span>';
};
$chosen = $placeholder === null ? $options[0] : null;
foreach ($options as $option) {
    if ($option['value'] === $current) {
        $chosen = $option;
    }
}
$title = $chosen === null ? $label : $label . ': ' . $chosen['label'];
$summary = function (array $option) use ($show, $mark): string {
    $html = '';
    if ($show !== 'text') {
        $html .= $mark($option);
    }
    if ($show !== 'icon') {
        $html .= '<span class="admin-media-option-text" data-option-text' . ($option['attrs'] ?? '') . '>' . $this->e($option['label']) . '</span>';
    }

    return $html;
};
?>
<details class="admin-menu admin-media-dropdown" data-media-dropdown>
    <summary class="admin-media-dropdown-trigger<?= $show === 'icon' ? ' admin-media-dropdown-icon' : '' ?>" title="<?= $this->e($title) ?>" aria-label="<?= $this->e($title) ?>" data-dropdown-label="<?= $this->e($label) ?>" data-dropdown-show="<?= $this->e($show) ?>"><span class="admin-media-dropdown-current" data-dropdown-current><?= $chosen === null ? '<span class="admin-media-option-text" data-option-text>' . $this->e($placeholder) . '</span>' : $summary($chosen) ?></span><?= $this->icon('chevron-down') ?></summary>
    <div class="admin-menu-panel">
        <fieldset class="admin-media-options">
            <legend class="admin-visually-hidden"><?= $this->e($label) ?></legend>
            <?php foreach ($options as $option): ?>
            <label class="admin-media-option"><input type="radio" class="admin-visually-hidden" name="<?= $this->e($name) ?>" value="<?= $this->e($option['value']) ?>"<?= $chosen !== null && $option['value'] === $chosen['value'] ? ' checked' : '' ?><?= $aria ?>><?= $mark($option) ?><span class="admin-media-option-text" data-option-text<?= $option['attrs'] ?? '' ?>><?= $this->e($option['label']) ?></span><span class="admin-media-option-check"><?= $this->icon('check') ?></span></label>
            <?php endforeach ?>
        </fieldset>
    </div>
</details>
