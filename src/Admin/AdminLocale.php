<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\User;
use AtarCms\I18n\RequestLocale;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware of the signed-in admin routes (after RequireAuthentication) and the login page: the admin's
 * interface language replaces the request locale for the rest of the pipeline, so the catalogs, <html lang> and dates
 * of the admin speak it (it need not be a site locale). Public routes never carry it, so the site keeps the locale of
 * its URLs; admin error pages get it through AdminErrorLocale.
 *
 * Separate mode: the user's interface language (InterfaceLanguage: the Profile's choice, else the browser's); the
 * login page keeps the site default. Linked mode (LinkedLanguage::enabled()): the screen's content language, decided
 * here before the controller runs: the item of the route (ItemLocale) → a valid ?locale= → the remembered language →
 * the browser's among the site locales → the site default (a non-GET without either takes a valid posted locale,
 * remembering nothing). An item's or ?locale='s language is remembered on a GET that the screen answers below 400
 * (users.content_locale; the request's user is updated first so the screen agrees) and put on the request (ScreenLocale);
 * the interface speaks it through LinkedLanguage::interfaceOf(). A guest gets LinkedLanguage::guest().
 */
final class AdminLocale implements MiddlewareInterface
{
    public function __construct(
        private readonly InterfaceLanguage $language,
        private readonly LinkedLanguage $linked,
        private readonly ItemLocale $items,
        private readonly ContentLocale $contentLocale,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = CurrentUser::of($request);
        if (!$this->linked->enabled()) {
            return $handler->handle($user === null ? $request : self::speaking($request, $this->language->of($user, $request)));
        }
        if ($user === null) {
            return $handler->handle(self::speaking($request, $this->linked->guest($request)));
        }
        $read = in_array($request->getMethod(), ['GET', 'HEAD'], true);
        $chosen = $this->items->of($request, $user) ?? $this->linked->requested($request);
        // A form posted without an item of its own re-renders in the language it was filled in (nothing is stored).
        $posted = $chosen === null && !$read ? $this->linked->posted($request) : null;
        $remember = $chosen !== null && $chosen !== $user->contentLocale && $read;
        if ($remember) {
            $request = $request->withAttribute(User::class, $user->withContentLocale($chosen));
        }
        $screen = $chosen ?? $posted ?? $this->linked->remembered($user, $request);
        $response = $handler->handle(self::speaking($request->withAttribute(ScreenLocale::class, new ScreenLocale($screen)), LinkedLanguage::interfaceOf($screen)));
        // Remembered only once the screen answered: a refused or missing one (an exception or a 4xx/5xx) stores nothing.
        if ($remember && $response->getStatusCode() < 400) {
            $this->contentLocale->store($user, $chosen);
        }

        return $response;
    }

    private static function speaking(ServerRequestInterface $request, string $locale): ServerRequestInterface
    {
        return $request->withAttribute(RequestLocale::class, new RequestLocale($locale, false));
    }
}
