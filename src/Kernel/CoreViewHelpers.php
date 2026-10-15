<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\User;
use AtarCms\Http\CanonicalUrl;
use AtarCms\Http\Security\CspNonce;
use AtarCms\Http\Security\CsrfToken;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\LanguageNames;
use AtarCms\I18n\Locales;
use AtarCms\I18n\LocalizedUrls;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\TextDirection;
use AtarCms\I18n\Translator;
use AtarCms\View\Renderer;
use DateTimeInterface;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Helpers shared by the theme renderer and the admin renderer.
 */
final class CoreViewHelpers
{
    public static function register(Renderer $renderer, Container $container): void
    {
        $renderer->requestHelper(
            'url',
            static function (ServerRequestInterface $request, string $name, array $params = [], ?string $locale = null) use ($container): string {
                if ($locale !== null && !$container->service(Locales::class)->isSupported($locale)) {
                    throw new InvalidArgumentException(sprintf('Unknown locale "%s"', $locale));
                }

                /** @var array<string, scalar> $params */
                return $container->service(LocalizedUrls::class)->path($name, $params, $locale ?? RequestLocale::of($request)->code);
            },
        );
        $renderer->requestHelper(
            't',
            static function (ServerRequestInterface $request, string $key, array $params = []) use ($container): string {
                /** @var array<string, mixed> $params */
                return $container->service(Translator::class)->translate(RequestLocale::of($request)->code, $key, $params);
            },
        );
        $renderer->requestHelper(
            'hasTranslation',
            static fn(ServerRequestInterface $request, string $key): bool => $container
                ->service(Translator::class)
                ->canTranslate(RequestLocale::of($request)->code, $key),
        );
        $renderer->requestHelper(
            'label',
            static fn(ServerRequestInterface $request, string $label): string => $container
                ->service(Translator::class)
                ->label(RequestLocale::of($request)->code, $label),
        );
        $renderer->requestHelper(
            'locale',
            static fn(ServerRequestInterface $request): string => RequestLocale::of($request)->code,
        );
        $renderer->requestHelper(
            'alternates',
            static fn(ServerRequestInterface $request): array => $container->service(LocalizedUrls::class)->alternates($request),
        );
        $renderer->requestHelper(
            'hreflang',
            static function (ServerRequestInterface $request) use ($container): string {
                $links = [];
                foreach ($container->service(LocalizedUrls::class)->hreflangs($request) as $locale => $url) {
                    $links[] = sprintf(
                        '<link rel="alternate" hreflang="%s" href="%s">',
                        htmlspecialchars($locale, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                        htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                    );
                }

                return implode("\n", $links);
            },
        );
        $renderer->requestHelper(
            'canonical',
            static function (ServerRequestInterface $request): string {
                $canonical = CanonicalUrl::of($request);

                return $canonical === null
                    ? ''
                    : sprintf('<link rel="canonical" href="%s">', htmlspecialchars($canonical->url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
            },
        );
        // Formats stored instants (published_at, created_at, ...) in the site time zone. date/datetime field values are
        // wall-clock values: format them with ->format(), not date(), or a date west of UTC prints as the previous day.
        $renderer->helper(
            'date',
            static fn(?DateTimeInterface $time, string $format = 'Y-m-d H:i'): string => $container->service(SiteTime::class)->format($time, $format),
        );
        // The same instant as a short date and time in the request's language ("Oct 4, 2026, 01:41"), or the day alone
        // with $withTime false ("Oct 4, 2026").
        $renderer->requestHelper(
            'readableDate',
            static fn(ServerRequestInterface $request, ?DateTimeInterface $time, bool $withTime = true): string => $container
                ->service(SiteTime::class)
                ->readable($time, RequestLocale::of($request)->code, $withTime),
        );
        $renderer->helper(
            'timezone',
            static fn(): string => $container->service(SiteTime::class)->zone->getName(),
        );
        // The lang and dir attributes of an element holding text of $locale, with their leading space:
        // ' lang="he" dir="rtl"' (the theme's <html>, the admin's content inputs).
        $renderer->helper(
            'langAttrs',
            static fn(string $locale): string => ' lang="' . htmlspecialchars($locale, ENT_QUOTES) . '" dir="' . TextDirection::of($locale) . '"',
        );
        // A language named in itself, the same in every interface language ("Русский", "עברית").
        $renderer->helper('languageName', static fn(string $code): string => LanguageNames::endonym($code));
        $renderer->requestHelper(
            'nonce',
            static fn(ServerRequestInterface $request): string => CspNonce::of($request)->value,
        );
        $renderer->requestHelper(
            'csrfToken',
            static fn(ServerRequestInterface $request): string => CsrfToken::get(Session::of($request)),
        );
        $renderer->requestHelper(
            'csrfField',
            static fn(ServerRequestInterface $request): string => sprintf(
                '<input type="hidden" name="%s" value="%s">',
                CsrfToken::FIELD,
                CsrfToken::get(Session::of($request)),
            ),
        );
        $renderer->requestHelper(
            'user',
            static fn(ServerRequestInterface $request): ?User => CurrentUser::of($request),
        );
        $renderer->requestHelper(
            'can',
            static fn(ServerRequestInterface $request, string $permission): bool => $container
                ->service(Authorizer::class)
                ->can(CurrentUser::of($request), $permission),
        );
    }
}
