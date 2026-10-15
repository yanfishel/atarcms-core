<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Auth\User;
use AtarCms\I18n\AcceptLanguage;
use AtarCms\I18n\InterfaceLanguages;
use AtarCms\I18n\Locales;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The language the admin speaks to a user: the one saved in the profile (users.locale) when the admin still has it,
 * else the browser's Accept-Language among the interface languages, else the site default when the admin speaks it,
 * else English. Nothing is stored without a choice.
 */
final readonly class InterfaceLanguage
{
    public function __construct(private Locales $locales) {}

    public function of(?User $user, ServerRequestInterface $request): string
    {
        return $this->chosen($user, $request)
            ?? (InterfaceLanguages::isSupported($this->locales->default()) ? $this->locales->default() : 'en');
    }

    /**
     * The user's saved language, else the browser's; null when neither names an interface language.
     */
    public function chosen(?User $user, ServerRequestInterface $request): ?string
    {
        $saved = $user?->locale;
        if ($saved !== null && InterfaceLanguages::isSupported($saved)) {
            return $saved;
        }

        return AcceptLanguage::negotiate($request->getHeaderLine('Accept-Language'), InterfaceLanguages::ALL);
    }
}
