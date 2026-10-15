<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Http\Routing\RouteMatch;

final readonly class AdminMenuItem
{
    /**
     * @param string $label a translation key or plain text
     * @param array<string, scalar> $params route parameters
     * @param string $icon a symbol of the admin icon sprite
     * @param string|null $module the class of the module that added the item (named by the menu's icon error)
     */
    public function __construct(
        public string $label,
        public string $route,
        public ?string $permission,
        public int $order,
        public array $params = [],
        public string $icon = AdminMenu::DEFAULT_ICON,
        public ?string $module = null,
    ) {}

    /**
     * The item is current on its own route and on its child routes ("admin.users" for "admin.users.edit"),
     * as long as the matched parameters include this item's parameters.
     */
    public function isCurrent(?RouteMatch $match): bool
    {
        $name = $match?->route->name;
        if ($match === null || $name === null || ($name !== $this->route && !str_starts_with($name, $this->route . '.'))) {
            return false;
        }
        foreach ($this->params as $param => $value) {
            if (($match->params[$param] ?? null) !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
