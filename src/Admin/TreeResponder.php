<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Http\Responses;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\Translator;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The answer to a tree editor submission (TreeForm) on every order screen. tree.js saves a dragged tree in the
 * background with "Accept: application/json" and gets JSON: {"version": …}, the stored tree's new version that its
 * next save (and the arrow buttons) must send, or, with 422, {"error": …}, the first error in the interface language.
 * A form post (the arrow buttons, every submission without JavaScript) gets the flash and a 303 redirect, or the
 * screen again with its errors.
 */
final class TreeResponder
{
    public function __construct(
        private readonly Responses $responses,
        private readonly Translator $translator,
    ) {}

    /**
     * @param Closure(): string $version the version of the stored tree after the save
     */
    public function saved(ServerRequestInterface $request, Closure $version, string $redirect): ResponseInterface
    {
        if (self::wantsJson($request)) {
            return $this->responses->json(['version' => $version()]);
        }
        Flash::success(Session::of($request), 'admin.tree.flash.saved');

        return $this->responses->redirect($redirect, 303);
    }

    /**
     * @param array<string, string> $errors field => translation key
     * @param array<string, array<string, int|float|string>> $params field => ICU parameters of its message
     * @param Closure(): ResponseInterface $page the screen again, with the errors
     */
    public function failed(ServerRequestInterface $request, array $errors, array $params, Closure $page): ResponseInterface
    {
        if (!self::wantsJson($request)) {
            return $page();
        }
        $field = array_key_first($errors) ?? 'tree';
        $key = $errors[$field] ?? 'validation.tree.invalid';
        // The page of a refused form post shows the stored order again; a refused background save leaves the dragged
        // order on the screen, so its stale message asks for a reload instead.
        $key = $key === 'validation.tree.stale' ? 'admin.tree.status.stale' : $key;
        $message = $this->translator->translate(RequestLocale::of($request)->code, $key, $params[$field] ?? []);

        return $this->responses->json(['error' => $message], 422);
    }

    private static function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }
}
