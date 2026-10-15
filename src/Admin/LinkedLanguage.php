<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Auth\User;
use AtarCms\I18n\AcceptLanguage;
use AtarCms\I18n\InterfaceLanguages;
use AtarCms\I18n\Locales;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The linked mode (LanguageMode::Linked on a site with more than one language): one language for the whole admin, the
 * content language of the screen, which the top bar switches. The interface speaks it when the admin has its catalog,
 * else English (interfaceOf()); content inputs keep the content language and its direction either way. AdminLocale
 * decides the screen's language (ItemLocale, ?locale=, then remembered()); the admin error pages use remembered() and
 * guest().
 */
final readonly class LinkedLanguage
{
    public function __construct(
        private LanguageMode $mode,
        private Locales $locales,
    ) {}

    /**
     * Whether the admin is linked: the mode says so and the site has languages to switch between (a one-language site
     * behaves as before, with the Profile's interface language).
     */
    public function enabled(): bool
    {
        return $this->mode === LanguageMode::Linked && count($this->locales->all()) > 1;
    }

    /**
     * The interface language for the content language $locale: itself when the admin has its catalog, else English.
     */
    public static function interfaceOf(string $locale): string
    {
        return InterfaceLanguages::isSupported($locale) ? $locale : 'en';
    }

    /**
     * A valid ?locale= of the request (a site locale), else null.
     */
    public function requested(ServerRequestInterface $request): ?string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) && $this->locales->isSupported($locale) ? $locale : null;
    }

    /**
     * A valid posted "locale" (a site locale) of a form without an item of its own, else null.
     */
    public function posted(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        $locale = is_array($body) ? ($body['locale'] ?? null) : null;

        return is_string($locale) && $this->locales->isSupported($locale) ? $locale : null;
    }

    /**
     * The language of a screen that names none: the remembered one (users.content_locale, while the site has it), else
     * the browser's among the site locales, else the site default.
     */
    public function remembered(?User $user, ServerRequestInterface $request): string
    {
        $stored = $user?->contentLocale;
        if ($stored !== null && $this->locales->isSupported($stored)) {
            return $stored;
        }

        return AcceptLanguage::negotiate($request->getHeaderLine('Accept-Language'), $this->locales->all()) ?? $this->locales->default();
    }

    /**
     * The interface language of a guest (the login page, guest admin error pages): the browser's among the site
     * locales the admin speaks, else the site default when the admin speaks it, else English.
     */
    public function guest(ServerRequestInterface $request): string
    {
        $spoken = array_values(array_filter($this->locales->all(), InterfaceLanguages::isSupported(...)));

        return AcceptLanguage::negotiate($request->getHeaderLine('Accept-Language'), $spoken) ?? self::interfaceOf($this->locales->default());
    }
}
