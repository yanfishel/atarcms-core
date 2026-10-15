<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\User;
use AtarCms\Auth\UserManager;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The content language of the admin screens (lists, "Add new", the order screens, the settings), switched for every
 * screen by the top bar's menu (POST /admin/preferences/content-locale) and remembered in the profile
 * (users.content_locale). A valid requested locale (the ?locale= that links and redirects carry with an item's
 * language) wins and is remembered too; without one the remembered language is used, then the interface language
 * the admin speaks (when it is a site locale), then the site default. A stored language the site no longer has is
 * ignored. In the linked mode AdminLocale has decided the screen's language before the controller (ScreenLocale),
 * and current() returns it.
 */
final class ContentLocale
{
    public function __construct(
        private readonly Locales $locales,
        private readonly UserManager $users,
    ) {}

    public function resolve(ServerRequestInterface $request, mixed $requested): string
    {
        if (is_string($requested) && $this->locales->isSupported($requested)) {
            $this->store(CurrentUser::require($request), $requested);

            return $requested;
        }

        return $this->current($request);
    }

    /**
     * The remembered content language, storing nothing: else the interface language when the site has it, else the
     * site default. Screens of one language pass their own to the layout instead (a ?locale= of theirs is remembered
     * by resolve() but not yet on the request's user).
     */
    public function current(ServerRequestInterface $request): string
    {
        // The linked mode decided the screen's language before the controller (AdminLocale).
        $screen = ScreenLocale::of($request);
        if ($screen !== null) {
            return $screen;
        }
        foreach ([CurrentUser::of($request)?->contentLocale, RequestLocale::of($request)->code] as $locale) {
            if ($locale !== null && $this->locales->isSupported($locale)) {
                return $locale;
            }
        }

        return $this->locales->default();
    }

    /**
     * On the edit page of an entry or term in $locale (reached through a link to a translation or after creating
     * one): remembers $locale when the request's ?locale= names exactly it; any other value is ignored.
     */
    public function rememberRequested(ServerRequestInterface $request, string $locale): void
    {
        if (($request->getQueryParams()['locale'] ?? null) === $locale && $this->locales->isSupported($locale)) {
            $this->store(CurrentUser::require($request), $locale);
        }
    }

    /**
     * Remembers $locale for the user (a preference, so writing it on a GET is fine); only a change is written.
     */
    public function store(User $user, string $locale): void
    {
        if ($locale !== $user->contentLocale) {
            $this->users->setContentLocale($user->id, $locale);
        }
    }
}
