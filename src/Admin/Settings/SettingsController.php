<?php

declare(strict_types=1);

namespace AtarCms\Admin\Settings;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\ContentLocale;
use AtarCms\Admin\Flash;
use AtarCms\Auth\CurrentUser;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\Locales;
use AtarCms\Media\Media;
use AtarCms\Media\MediaRepository;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The "Site settings" screen: the values of one content language (?locale=, the switch in the header) and the
 * site-wide ones, in the tabs and the images card of SettingsForm. Routes require "settings.manage".
 */
final class SettingsController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly SiteSettings $settings,
        private readonly SettingsService $service,
        private readonly ContentLocale $contentLocale,
        private readonly Locales $locales,
        private readonly MediaRepository $media,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $locale = $this->contentLocale->resolve($request, $request->getQueryParams()['locale'] ?? null);

        return $this->form($request, $locale, SettingsForm::values($this->settings->of($locale)), [], []);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $values = SettingsForm::submitted($body);
        $posted = is_string($body['locale'] ?? null) ? $body['locale'] : '';
        $locale = $this->locales->isSupported($posted) ? $posted : $this->locales->default();
        $invalid = SettingsForm::invalidIds($values);
        if ($invalid !== []) {
            return $this->form($request, $locale, $values, $invalid, [], 422);
        }

        try {
            // The contact details live on the contact page's form: the stored ones are saved as they are.
            $input = SettingsForm::input([...SettingsForm::values($this->settings->of($locale)), ...$values]);
            $this->service->save($posted, $input, CurrentUser::require($request), ClientIp::of($request));
        } catch (ValidationFailed $e) {
            return $this->form($request, $locale, $values, $e->errors, $e->params, 422);
        }
        Flash::success(Session::of($request), 'admin.settings.flash.saved');

        return $this->responses->redirect($this->router->url('admin.settings', ['locale' => $locale]), 303);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function form(ServerRequestInterface $request, string $locale, array $values, array $errors, array $params, int $status = 200): ResponseInterface
    {
        $images = [];
        foreach (array_keys(SettingsForm::tab(SettingsForm::IMAGES)) as $name) {
            $value = trim($values[$name]);
            $media = preg_match('/^[1-9]\d{0,9}$/', $value) === 1 ? $this->media->find((int) $value) : null;
            if ($media instanceof Media) {
                $images[$media->id] = $media;
            }
        }

        return $this->responses->html($this->view->render('settings/form', $request, [
            'locale' => $locale,
            'values' => $values,
            'errors' => $errors,
            'params' => $params,
            'images' => $images,
        ]), $status);
    }
}
