<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\LoginService;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Http\Error\ErrorRenderer;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\RequestLocale;
use AtarCms\Settings\AdminAccent;
use Closure;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Renders admin error pages in the user's interface language and admin colour (the request attribute
 * AdminAccent::class, only when the user chose one), as AdminLocale does for the admin's pages: the
 * signed-in user's language as on the admin pages (InterfaceLanguage::of()), a guest's browser language. ErrorMiddleware renders
 * with the request from before the route middleware (inside the session stack it carries the session, not the user),
 * so the user is looked up from the session here. Without a choice, a database or a browser language, the page keeps
 * the request's language (the site default).
 */
final class AdminErrorLocale implements ErrorRenderer
{
    /**
     * @param Closure(): UserRepository $users resolved only for an admin error page (the database is not touched otherwise)
     */
    public function __construct(
        private readonly ErrorRenderer $inner,
        private readonly Closure $users,
        private readonly InterfaceLanguage $language,
        private readonly LinkedLanguage $linked,
    ) {}

    public function render(Throwable $error, int $status, ServerRequestInterface $request): string
    {
        // A signed-in user's error page speaks like the user's admin pages; a guest's only follows the browser.
        $user = $this->user($request);
        $locale = match (true) {
            // The linked mode: the remembered language (an error page has no item), a guest the browser's site language.
            $this->linked->enabled() => $user !== null ? LinkedLanguage::interfaceOf($this->linked->remembered($user, $request)) : $this->linked->guest($request),
            $user !== null => $this->language->of($user, $request),
            default => $this->language->chosen(null, $request),
        };
        if ($locale !== null) {
            $request = $request->withAttribute(RequestLocale::class, new RequestLocale($locale, false));
        }
        // And in the user's admin colour (the adminBrand helper reads it; the request carries no user here).
        if ($user?->accent !== null) {
            $request = $request->withAttribute(AdminAccent::class, $user->accent);
        }

        return $this->inner->render($error, $status, $request);
    }

    private function user(ServerRequestInterface $request): ?User
    {
        $user = CurrentUser::of($request);
        if ($user !== null) {
            return $user;
        }
        $session = $request->getAttribute(Session::class);
        $id = $session instanceof Session ? LoginService::userId($session) : null;
        if ($id === null) {
            return null;
        }
        try {
            $user = ($this->users)()->find($id);
        } catch (Throwable) {
            return null;
        }

        return $user !== null && $user->isActive() ? $user : null;
    }
}
