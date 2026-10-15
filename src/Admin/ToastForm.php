<?php

declare(strict_types=1);

namespace AtarCms\Admin;

/**
 * The action of a page toast as a plain POST form (partials/toast.php adds the CSRF field): it works without
 * JavaScript, unlike the data-toast-action button a script wires. Used by the offer to copy an item for a missing
 * translation, whose machine translation button (Plan 38: $machineFrom, the item's language; the target is the posted
 * "locale") is a second submit button before it, hidden until translate.js finds the browser can translate the pair.
 * Both are compact buttons on one row with short visible labels ($shortLabel, "Copy"); the full label stays their
 * accessible name and tooltip.
 */
final readonly class ToastForm
{
    /**
     * @param string $action the form's URL (built by the router)
     * @param array<string, string> $fields hidden fields
     * @param string $label the button's translation key
     * @param array<string, int|float|string> $labelParams its ICU parameters
     * @param string|null $machineFrom the source language of the machine translation button (MachineTranslation), null for none
     * @param string|null $shortLabel the translation key of the button's shorter visible label (same parameters), null to show $label
     */
    public function __construct(
        public string $action,
        public array $fields,
        public string $label,
        public array $labelParams = [],
        public ?string $machineFrom = null,
        public ?string $shortLabel = null,
    ) {}
}
