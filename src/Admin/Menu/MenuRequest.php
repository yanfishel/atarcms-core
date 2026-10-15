<?php

declare(strict_types=1);

namespace AtarCms\Admin\Menu;

use AtarCms\Admin\ContentLocale;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Menu\MenuItem;
use AtarCms\Content\Menu\MenuRepository;
use AtarCms\Http\Exception\NotFound;
use AtarCms\I18n\Locales;
use AtarCms\View\ThemeConfig;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the menu location and item named in an admin menu URL; anything that does not match is a 404.
 */
final class MenuRequest
{
    public function __construct(
        private readonly ThemeConfig $theme,
        private readonly MainMenu $mainMenu,
        private readonly MenuRepository $menus,
        private readonly Locales $locales,
        private readonly ContentLocale $contentLocale,
    ) {}

    /**
     * The location of the URL; one the theme does not declare is a 404, and so is "main" when it is the menu of sections
     * (MainMenu: the Sections screen orders it).
     */
    public function location(ServerRequestInterface $request): string
    {
        $location = $request->getAttribute('location');
        if (!is_string($location) || !isset($this->mainMenu->menuLocations($this->theme)[$location])) {
            throw new NotFound('No such menu location');
        }

        return $location;
    }

    /**
     * The item of the URL's id; an item of another location is a 404.
     */
    public function item(ServerRequestInterface $request, string $location): MenuItem
    {
        $id = $request->getAttribute('id');
        $item = is_string($id) && strlen($id) <= 10 ? $this->menus->find((int) $id) : null;
        if ($item === null || $item->location !== $location) {
            throw new NotFound(sprintf('No %s menu item #%s', $location, is_string($id) ? $id : '?'));
        }

        return $item;
    }

    /**
     * The content language of a screen that takes it from ?locale= (the menu screen, "Add new"): the requested
     * locale, remembered for the user, or the user's preference (ContentLocale).
     */
    public function contentLocale(ServerRequestInterface $request): string
    {
        return $this->contentLocale->resolve($request, $request->getQueryParams()['locale'] ?? null);
    }

    /**
     * A supported locale (a submitted form's locale); the default locale otherwise.
     */
    public function locale(mixed $value): string
    {
        return is_string($value) && $this->locales->isSupported($value) ? $value : $this->locales->default();
    }
}
