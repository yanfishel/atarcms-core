<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\User;
use AtarCms\Kernel\ConfigException;
use InvalidArgumentException;

final class AdminMenu
{
    /*
     * Order bands: every group of items has its own range, so a long list of collections or taxonomies never
     * runs into the next group. Items within a band are ordered by their offset, then by label.
     */
    public const int DASHBOARD = 0;

    public const int CONTENT = 1000;

    public const int TAXONOMIES = 2000;

    /** The menus on a site without sections, after the taxonomies; with sections they come right after them. */
    public const int MENUS = 3000;

    public const int MEDIA = 4000;

    /** The default for items added by site modules. */
    public const int MODULES = 5000;

    /** The site settings: the first item of the "Settings" group. */
    public const int SETTINGS = 7000;

    public const int USERS = 8000;

    public const int AUDIT = 9000;

    /** The icon of items that name none. */
    public const string DEFAULT_ICON = 'circle';

    /** @var list<AdminMenuItem> */
    private array $items = [];

    /** The module whose adminMenu() is adding items now (AdminModule sets it before each one). */
    private ?string $module = null;

    /**
     * Marks the items added from now on as the given module's, so a mistake in one names its source.
     *
     * @param class-string $module
     */
    public function from(string $module): void
    {
        $this->module = $module;
    }

    /**
     * @param string $label a translation key or plain text
     * @param array<string, scalar> $params route parameters, e.g. ['collection' => 'news']
     * @param string $icon a symbol of the admin icon sprite (resources/admin/assets/icons.svg), e.g. "newspaper"
     */
    public function add(
        string $label,
        string $route,
        ?string $permission = null,
        int $order = self::MODULES,
        array $params = [],
        string $icon = self::DEFAULT_ICON,
    ): void {
        if (preg_match(AdminIcon::NAME_PATTERN, $icon) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid icon "%s" for the admin menu item "%s"', $icon, $label));
        }
        $this->items[] = new AdminMenuItem($label, $route, $permission, $order, $params, $icon, $this->module);
    }

    /**
     * Refuses an item whose icon is not a symbol of the sprite (a typo would render a blank icon). AdminModule checks
     * the menu when it builds it, so the first admin page after a config change names the mistake.
     *
     * @param list<string> $symbols the sprite's symbol ids (AdminIcon::symbols())
     *
     * @throws ConfigException
     */
    public function assertIcons(array $symbols): void
    {
        foreach ($this->items as $item) {
            if (!in_array($item->icon, $symbols, true)) {
                throw new ConfigException(sprintf(
                    'The admin menu item "%s"%s uses the icon "%s", which is not in resources/admin/assets/icons.svg; choose one of its symbols',
                    $item->label,
                    $item->module === null ? '' : ' of ' . $item->module,
                    $item->icon,
                ));
            }
        }
    }

    /**
     * @return list<AdminMenuItem>
     */
    public function items(): array
    {
        $items = $this->items;
        usort($items, static fn(AdminMenuItem $a, AdminMenuItem $b): int => [$a->order, $a->label] <=> [$b->order, $b->label]);

        return $items;
    }

    /**
     * The items a user may open: those without a permission and those whose permission the user has (the sidebar and
     * the AI guide).
     *
     * @return list<AdminMenuItem>
     */
    public function visibleTo(Authorizer $authorizer, ?User $user): array
    {
        return array_values(array_filter(
            $this->items(),
            static fn(AdminMenuItem $item): bool => $item->permission === null || $authorizer->can($user, $item->permission),
        ));
    }
}
