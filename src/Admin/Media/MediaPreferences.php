<?php

declare(strict_types=1);

namespace AtarCms\Admin\Media;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The media screens' remembered choices, as cookies the server reads (so the first paint is right, also without
 * JavaScript): the view of the library and of the picker, and the picker's last folder. A request naming a view
 * (?view=) or, in the picker, a folder (?folder=, 0 for the root) stores it. Not security-sensitive.
 */
final class MediaPreferences
{
    public const string LIBRARY_VIEW = 'admin_media_view';

    public const string PICKER_VIEW = 'admin_picker_view';

    public const string PICKER_FOLDER = 'admin_picker_folder';

    private const int MAX_AGE = 31536000;

    /**
     * The view to show: ?view= when valid, else the cookie's, else the grid.
     */
    public static function view(ServerRequestInterface $request, string $cookie): string
    {
        $asked = $request->getQueryParams()['view'] ?? null;
        if (is_string($asked) && in_array($asked, MediaListQuery::VIEWS, true)) {
            return $asked;
        }
        $stored = $request->getCookieParams()[$cookie] ?? null;

        return is_string($stored) && in_array($stored, MediaListQuery::VIEWS, true) ? $stored : 'grid';
    }

    /**
     * The picker's folder as asked (?folder=), else as remembered; null for the root or nothing usable.
     */
    public static function pickerFolder(ServerRequestInterface $request): ?int
    {
        $query = $request->getQueryParams();

        return MediaFoldersController::folderId(array_key_exists('folder', $query) ? $query['folder'] : ($request->getCookieParams()[self::PICKER_FOLDER] ?? null));
    }

    /**
     * Stores the view a request asked for in $cookie, and with $folder (the picker) the folder shown.
     *
     * @param string $appUrl the site's address (Config::appUrl()): https makes the cookies Secure
     * @param bool $picker also remember the folder shown ($folder, null for the root)
     */
    public static function remember(ServerRequestInterface $request, ResponseInterface $response, string $cookie, string $appUrl, bool $picker = false, ?int $folder = null): ResponseInterface
    {
        $secure = str_starts_with(strtolower($appUrl), 'https://');
        $cookies = $request->getCookieParams();
        $view = $request->getQueryParams()['view'] ?? null;
        if (is_string($view) && in_array($view, MediaListQuery::VIEWS, true) && ($cookies[$cookie] ?? null) !== $view) {
            $response = $response->withAddedHeader('Set-Cookie', self::cookie($cookie, $view, $secure));
        }
        $stored = (string) ($folder ?? 0);
        if ($picker && ($cookies[self::PICKER_FOLDER] ?? null) !== $stored) {
            $response = $response->withAddedHeader('Set-Cookie', self::cookie(self::PICKER_FOLDER, $stored, $secure));
        }

        return $response;
    }

    private static function cookie(string $name, string $value, bool $secure): string
    {
        return sprintf('%s=%s; Path=/admin; Max-Age=%d; HttpOnly; SameSite=Lax%s', $name, $value, self::MAX_AGE, $secure ? '; Secure' : '');
    }
}
