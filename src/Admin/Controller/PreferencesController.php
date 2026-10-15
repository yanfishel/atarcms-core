<?php

declare(strict_types=1);

namespace AtarCms\Admin\Controller;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\ContentLocale;
use AtarCms\Admin\ContentLocaleSwitch;
use AtarCms\Admin\Flash;
use AtarCms\Admin\LinkedLanguage;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\UserManager;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\InterfaceLanguages;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The signed-in user's own settings: the Profile page (the interface language, one of the admin catalogs, and the
 * admin colour) and the top bar's content language.
 * Personal preferences are not audited.
 */
final class PreferencesController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly UserManager $users,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly Locales $locales,
        private readonly ContentLocale $contentLocale,
        private readonly ContentLocaleSwitch $switch,
        private readonly SiteSettings $settings,
        private readonly LinkedLanguage $linked,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $user = CurrentUser::require($request);

        return $this->profile($request, RequestLocale::of($request)->code, $user->accent->value ?? '', [], 200);
    }

    /**
     * Saves the Profile's interface language and admin colour together (UserManager::savePreferences()) and returns
     * to the Profile with a toast; a refusal shows the Profile again with the posted choices and the errors at their
     * fields (422), storing nothing. The linked mode's Profile has no interface language: a posted
     * locale is ignored (as fields a screen does not show are) and users.locale stays as it is.
     */
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $user = CurrentUser::require($request);
        $body = $request->getParsedBody();
        $locale = $this->linked->enabled() ? null : (is_array($body) && is_string($body['locale'] ?? null) ? $body['locale'] : '');
        $accent = is_array($body) && is_string($body['accent'] ?? null) ? $body['accent'] : '';
        try {
            $this->users->savePreferences($user->id, $locale, $accent);
        } catch (ValidationFailed $e) {
            return $this->profile($request, $locale ?? RequestLocale::of($request)->code, $accent, $e->errors, 422);
        }
        Flash::success(Session::of($request), 'admin.profile.flash.saved');

        return $this->responses->redirect($this->router->url('admin.profile'), 303);
    }

    /**
     * @param array<string, string> $errors
     */
    private function profile(ServerRequestInterface $request, string $locale, string $accent, array $errors, int $status): ResponseInterface
    {
        return $this->responses->html($this->view->render('profile/show', $request, [
            'languages' => InterfaceLanguages::ALL,
            'current' => $locale,
            'accent' => $accent,
            'siteAccent' => $this->settings->of($this->locales->default())->accent(),
            'errors' => $errors,
        ]), $status);
    }

    /**
     * The top bar's content language switch: remembers the language and returns to the screen it was used on, in
     * that language (ContentLocaleSwitch). A language the site does not have is refused and nothing is stored.
     */
    public function contentLocale(ServerRequestInterface $request): ResponseInterface
    {
        $user = CurrentUser::require($request);
        $body = $request->getParsedBody();
        $locale = is_array($body) ? ($body['locale'] ?? null) : null;
        if (!is_string($locale) || !$this->locales->isSupported($locale)) {
            throw new ValidationFailed(['locale' => 'validation.locale.content']);
        }
        $this->contentLocale->store($user, $locale);

        return $this->responses->redirect($this->switch->target($user, $body['return'] ?? null, $locale, ClientIp::of($request)), 303);
    }
}
