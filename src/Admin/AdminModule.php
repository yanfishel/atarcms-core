<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Admin\Content\ContactMapController;
use AtarCms\Admin\Content\EditorConfig;
use AtarCms\Admin\Content\EntriesController;
use AtarCms\Admin\Content\EntryActionsController;
use AtarCms\Admin\Content\EntryOrderController;
use AtarCms\Admin\Content\EntryPreviewController;
use AtarCms\Admin\Controller\AiGuideController;
use AtarCms\Admin\Controller\AuditLogController;
use AtarCms\Admin\Controller\DashboardController;
use AtarCms\Admin\Controller\PreferencesController;
use AtarCms\Admin\Controller\UsersController;
use AtarCms\Admin\Media\MediaBulkController;
use AtarCms\Admin\Media\MediaController;
use AtarCms\Admin\Media\MediaFoldersController;
use AtarCms\Admin\Media\MediaItemController;
use AtarCms\Admin\Menu\MenuItemsController;
use AtarCms\Admin\Menu\MenusController;
use AtarCms\Admin\Messages\MessagesController;
use AtarCms\Admin\Settings\SettingsController;
use AtarCms\Admin\Taxonomy\SectionArticlesController;
use AtarCms\Admin\Taxonomy\SectionsController;
use AtarCms\Admin\Taxonomy\TermActionsController;
use AtarCms\Admin\Taxonomy\TermOrderController;
use AtarCms\Admin\Taxonomy\TermsController;
use AtarCms\Auth\AuditLog;
use AtarCms\Auth\Authorizer;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\Http\RequireAuthentication;
use AtarCms\Auth\Http\RequirePermission;
use AtarCms\Auth\Roles;
use AtarCms\Auth\UserRepository;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Menu\MenuService;
use AtarCms\Content\Taxonomy;
use AtarCms\Http\Error\DebugErrorRenderer;
use AtarCms\Http\Error\ErrorRenderer;
use AtarCms\Http\Error\PathPrefixErrorRenderer;
use AtarCms\Http\Error\ThemeErrorRenderer;
use AtarCms\Http\RequestMemo;
use AtarCms\Http\Routing\RouteMatch;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequireDefaultLocale;
use AtarCms\I18n\TextDirection;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Container;
use AtarCms\Kernel\CoreViewHelpers;
use AtarCms\Kernel\Module;
use AtarCms\Kernel\ModuleList;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\Media;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaUrls;
use AtarCms\Messages\MessageRepository;
use AtarCms\Messages\MessageService;
use AtarCms\Settings\AdminAccent;
use AtarCms\Settings\AtarLogo;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteIcon;
use AtarCms\Settings\SiteSettings;
use AtarCms\View\AssetUrl;
use AtarCms\View\Renderer;
use AtarCms\View\ThemeConfig;
use AtarCms\View\ThemeResolver;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class AdminModule extends Module
{
    public function services(Container $container): void
    {
        $container->factory(AdminMenu::class, static function (Container $c): AdminMenu {
            $menu = new AdminMenu();
            foreach ($c->service(ModuleList::class)->modules as $module) {
                $menu->from($module::class);
                $module->adminMenu($menu);
            }
            // Collection::icon(), Taxonomy::icon() and module items name sprite symbols: a typo fails here, on the
            // first admin page, instead of rendering a blank icon. Only admin pages build the menu.
            $menu->assertIcons(AdminIcon::symbols(dirname(__DIR__, 2) . '/resources/admin/assets/icons.svg'));

            return $menu;
        });

        $container->factory(AdminView::class, static function (Container $c): AdminView {
            $resources = dirname(__DIR__, 2) . '/resources/admin';
            $config = $c->service(Config::class);
            $renderer = new Renderer(new ThemeResolver([
                $resources . '/templates',
                ...$c->service(ModuleList::class)->adminTemplatePaths(),
            ]));
            CoreViewHelpers::register($renderer, $c);

            $assets = new AssetUrl($resources . '/assets', $config->string('app.admin_assets_url', '/admin-assets'));
            $renderer->helper('asset', static fn(string $path): string => $assets->url($path));
            $renderer->helper('icon', static fn(string $name, ?string $label = null): string => AdminIcon::svg($assets->url('icons.svg'), $name, $label));
            $renderer->helper('siteLocales', static fn(): array => $c->service(Locales::class)->all());
            // Whether the site has more than one language: a site with one shows nothing about languages.
            $renderer->helper('multilingual', static fn(): bool => count($c->service(Locales::class)->all()) > 1);
            // Whether one language switch drives the interface and the content (the linked mode of a multilingual site).
            $renderer->helper('linkedLanguage', static fn(): bool => $c->service(LinkedLanguage::class)->enabled());
            // The admin brand, read once per request: the short name (else the site name) of the default language, the admin colour and the
            // mark (the site icon, else the AtarCMS logo in that colour). An admin error page of a broken database falls back to the theme
            // catalog's name (else app.name), green and the logo.
            $logo = new AtarLogo($assets);
            $brand = static fn(ServerRequestInterface $request): AdminBrand => RequestMemo::through(
                $request,
                'admin-brand',
                static function () use ($c, $config, $logo, $request): AdminBrand {
                    $default = $c->service(Locales::class)->default();
                    try {
                        $site = $c->service(SiteSettings::class);
                        $settings = $site->of($default);
                        $icon = $c->service(SiteIcon::class);
                        $image = $icon->of($settings);
                        // The signed-in user's own colour (on an admin error page AdminErrorLocale passes it), else the site colour.
                        $chosen = CurrentUser::of($request)->accent ?? $request->getAttribute(AdminAccent::class);
                        $accent = $chosen instanceof AdminAccent ? $chosen : $settings->accent();

                        return $image === null
                            ? new AdminBrand($site->shortName($default, $settings), $accent, $logo->svg($accent), false)
                            : new AdminBrand($site->shortName($default, $settings), $accent, $icon->url($image, 'icon_180'), true);
                    } catch (Throwable) {
                        $translator = $c->service(Translator::class);
                        $name = $translator->canTranslate($default, SiteSettings::CATALOG_NAME)
                            ? $translator->translate($default, SiteSettings::CATALOG_NAME)
                            : $config->string('app.name', 'AtarCMS');

                        return new AdminBrand($name, AdminAccent::DEFAULT, $logo->svg(AdminAccent::DEFAULT), false);
                    }
                },
            );
            $renderer->requestHelper('adminBrand', $brand);
            $renderer->requestHelper('siteName', static fn(ServerRequestInterface $request): string => $brand($request)->name);
            $renderer->helper('roleLabel', static fn(string $role): string => $c->service(Roles::class)->label($role));
            $renderer->helper('mediaUrl', static fn(Media $media): string => $c->service(MediaUrls::class)->original($media));
            $renderer->helper('mediaThumb', static fn(Media $media): string => $c->service(MediaUrls::class)->variant($media, ImageVariants::ADMIN, true));
            // The theme's own assets (its editor stylesheets), through the theme's AssetUrl.
            $renderer->helper('themeAsset', static fn(string $path): string => $c->service(AssetUrl::class)->url($path));
            // A language's flag from the theme (assets/flags/{code}.png, as the public language menu shows it), or null.
            $renderer->helper('languageFlag', static function (string $code) use ($c): ?string {
                $assets = $c->service(AssetUrl::class);

                return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $code) === 1 && $assets->exists('flags/' . $code . '.png')
                    ? $assets->url('flags/' . $code . '.png')
                    : null;
            });
            $renderer->helper('editorStylesheets', static fn(): array => $c->service(ThemeConfig::class)->editor->css);
            // The content language of the page (ContentLocale::current(), storing nothing) for screens that do not
            // pass their own to the layout ($contentLocale).
            $renderer->requestHelper('contentLocale', static fn(ServerRequestInterface $request): string => RequestMemo::through(
                $request,
                'admin:content-locale',
                static fn(): string => $c->service(ContentLocale::class)->current($request),
            ));
            // The page's own path and query (only the path after a POST), where the content language switch returns.
            $renderer->requestHelper('requestTarget', static function (ServerRequestInterface $request): string {
                $uri = $request->getUri();

                return $request->getMethod() === 'GET' && $uri->getQuery() !== '' ? $uri->getPath() . '?' . $uri->getQuery() : $uri->getPath();
            });
            $renderer->requestHelper('editorConfig', static fn(ServerRequestInterface $request, bool $minimal = false): string => RequestMemo::through(
                $request,
                'admin:editor-config:' . ($minimal ? 'minimal' : 'full'),
                static fn(): string => $c->service(EditorConfig::class)->json($request, $minimal),
            ));
            $renderer->requestHelper('adminMenu', static fn(ServerRequestInterface $request): array => $c->service(AdminMenu::class)->visibleTo(
                $c->service(Authorizer::class),
                CurrentUser::of($request),
            ));
            // The top bar's inbox: the unread messages of the contact form, null for those who do not read them.
            $renderer->requestHelper('unreadMessages', static fn(ServerRequestInterface $request): ?int => $c->service(Authorizer::class)->can(CurrentUser::of($request), MessageService::PERMISSION)
                ? RequestMemo::through($request, 'admin:unread-messages', static fn(): int => $c->service(MessageRepository::class)->unreadCount())
                : null);
            $renderer->requestHelper('flashes', static function (ServerRequestInterface $request): array {
                $session = $request->getAttribute(Session::class);

                return $session instanceof Session ? Flash::pull($session) : [];
            });
            $renderer->requestHelper('isCurrentMenuItem', static function (ServerRequestInterface $request, AdminMenuItem $item): bool {
                $match = $request->getAttribute(RouteMatch::class);

                return $item->isCurrent($match instanceof RouteMatch ? $match : null);
            });
            $renderer->requestHelper(
                'widget',
                static function (ServerRequestInterface $request, Field $field, mixed $value, array $options = [], array $kept = [], bool $invalid = false, ?string $locale = null) use ($renderer): string {
                    $type = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $field->type->name()));
                    $templates = preg_match('/^[a-z0-9-]+$/D', $type) === 1 ? ['fields/' . $type, 'fields/default'] : ['fields/default'];
                    $id = 'field-' . $field->name;

                    return $renderer->render($templates, [
                        'field' => $field,
                        'name' => 'fields[' . $field->name . ']',
                        'id' => $id,
                        'value' => $value,
                        'options' => $options,
                        'kept' => $kept,
                        'invalid' => $invalid,
                        // The attributes of the widget's (primary) control when the field has an error; field.php
                        // renders the error line as "{$id}-error".
                        'aria' => $invalid ? ' aria-invalid="true" aria-describedby="' . htmlspecialchars($id . '-error', ENT_QUOTES) . '"' : '',
                        // The lang and dir of the entry's language for widgets holding its text ('' without one).
                        'lang' => $locale === null ? '' : ' lang="' . htmlspecialchars($locale, ENT_QUOTES) . '" dir="' . TextDirection::of($locale) . '"',
                    ], $request);
                },
            );

            return new AdminView($renderer);
        });

        $container->factory(ErrorRenderer::class, static fn(Container $c): ErrorRenderer => $c->service(Config::class)->bool('app.debug', false)
            ? new DebugErrorRenderer()
            : new PathPrefixErrorRenderer(
                '/admin',
                new AdminErrorLocale(
                    new ThemeErrorRenderer($c->service(AdminView::class)->renderer(), $c->service(LoggerInterface::class)),
                    static fn(): UserRepository => $c->service(UserRepository::class),
                    $c->service(InterfaceLanguage::class),
                    $c->service(LinkedLanguage::class),
                ),
                $c->service(ThemeErrorRenderer::class),
            ));
    }

    public function routes(Router $router): void
    {
        $router->group('/admin', static function (Router $admin): void {
            $admin->get('/', [DashboardController::class, 'index'], 'admin.dashboard');
            $admin->get('/profile', [PreferencesController::class, 'show'], 'admin.profile');
            $admin->post('/profile', [PreferencesController::class, 'save'], 'admin.profile.save');
            $admin->post('/preferences/content-locale', [PreferencesController::class, 'contentLocale'], 'admin.preferences.content_locale');
            // The admin described for AI browser agents, limited to what the user may open (AiGuide).
            $admin->get('/ai-guide', [AiGuideController::class, 'show'], 'admin.ai_guide');

            RequirePermission::on($admin->get('/users', [UsersController::class, 'index'], 'admin.users'), 'users.manage');
            RequirePermission::on($admin->get('/users/new', [UsersController::class, 'create'], 'admin.users.new'), 'users.manage');
            RequirePermission::on($admin->post('/users', [UsersController::class, 'store'], 'admin.users.store'), 'users.manage');
            RequirePermission::on($admin->get('/users/{id:\d+}', [UsersController::class, 'edit'], 'admin.users.edit'), 'users.manage');
            RequirePermission::on($admin->post('/users/{id:\d+}', [UsersController::class, 'update'], 'admin.users.update'), 'users.manage');
            RequirePermission::on($admin->get('/users/{id:\d+}/delete', [UsersController::class, 'confirmDelete'], 'admin.users.delete.confirm'), 'users.manage');
            RequirePermission::on($admin->post('/users/{id:\d+}/delete', [UsersController::class, 'delete'], 'admin.users.delete'), 'users.manage');
            RequirePermission::on($admin->get('/audit', [AuditLogController::class, 'index'], 'admin.audit'), AuditLog::PERMISSION);
            RequirePermission::on($admin->get('/messages', [MessagesController::class, 'index'], 'admin.messages'), MessageService::PERMISSION);
            RequirePermission::on($admin->get('/messages/{id:\d+}', [MessagesController::class, 'show'], 'admin.messages.show'), MessageService::PERMISSION);
            RequirePermission::on($admin->post('/messages/{id:\d+}/unread', [MessagesController::class, 'unread'], 'admin.messages.unread'), MessageService::PERMISSION);
            RequirePermission::on($admin->get('/messages/{id:\d+}/delete', [MessagesController::class, 'confirmDelete'], 'admin.messages.delete.confirm'), MessageService::PERMISSION);
            RequirePermission::on($admin->post('/messages/{id:\d+}/delete', [MessagesController::class, 'delete'], 'admin.messages.delete'), MessageService::PERMISSION);
            RequirePermission::on($admin->get('/settings', [SettingsController::class, 'show'], 'admin.settings'), SettingsService::PERMISSION);
            RequirePermission::on($admin->post('/settings', [SettingsController::class, 'save'], 'admin.settings.save'), SettingsService::PERMISSION);

            // The site's map, from the contact page's entry form (ContactMapController checks the rights).
            $admin->get('/contact-map', [ContactMapController::class, 'show'], 'admin.map');
            $admin->post('/contact-map', [ContactMapController::class, 'save'], 'admin.map.save');
            $admin->get('/contact-map/delete', [ContactMapController::class, 'confirmDelete'], 'admin.map.delete.confirm');
            $admin->post('/contact-map/delete', [ContactMapController::class, 'delete'], 'admin.map.delete');

            $collection = '/content/{collection:[a-z][a-z0-9_]*}';
            $admin->get($collection, [EntriesController::class, 'index'], 'admin.entries');
            $admin->get($collection . '/new', [EntriesController::class, 'create'], 'admin.entries.new');
            $admin->get($collection . '/order', [EntryOrderController::class, 'show'], 'admin.entries.order');
            $admin->post($collection . '/order', [EntryOrderController::class, 'save'], 'admin.entries.order.save');
            $admin->post($collection, [EntriesController::class, 'store'], 'admin.entries.store');
            $admin->post($collection . '/preview', [EntryPreviewController::class, 'previewNew'], 'admin.entries.preview.new');
            $admin->get($collection . '/{id:\d+}', [EntriesController::class, 'edit'], 'admin.entries.edit');
            $admin->post($collection . '/{id:\d+}', [EntriesController::class, 'update'], 'admin.entries.update');
            $admin->post($collection . '/{id:\d+}/preview', [EntryPreviewController::class, 'preview'], 'admin.entries.preview');
            $admin->post($collection . '/autosave', [EntriesController::class, 'autosaveNew'], 'admin.entries.autosave.new');
            $admin->post($collection . '/{id:\d+}/autosave', [EntriesController::class, 'autosave'], 'admin.entries.autosave');
            $admin->post($collection . '/{id:\d+}/translate', [EntryActionsController::class, 'translate'], 'admin.entries.translate');
            $admin->get($collection . '/{id:\d+}/delete', [EntryActionsController::class, 'confirmDelete'], 'admin.entries.delete.confirm');
            $admin->post($collection . '/{id:\d+}/delete', [EntryActionsController::class, 'delete'], 'admin.entries.delete');
            $admin->get($collection . '/{id:\d+}/revisions', [EntryActionsController::class, 'revisions'], 'admin.entries.revisions');
            $admin->post($collection . '/{id:\d+}/revisions/{revision:\d+}/restore', [EntryActionsController::class, 'restore'], 'admin.entries.restore');

            $taxonomy = '/taxonomies/{taxonomy:[a-z][a-z0-9_]*}';
            RequirePermission::on($admin->get($taxonomy, [TermsController::class, 'index'], 'admin.terms'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->get($taxonomy . '/new', [TermsController::class, 'create'], 'admin.terms.new'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->get($taxonomy . '/order', [TermOrderController::class, 'show'], 'admin.terms.order'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post($taxonomy . '/order', [TermOrderController::class, 'save'], 'admin.terms.order.save'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post($taxonomy, [TermsController::class, 'store'], 'admin.terms.store'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->get($taxonomy . '/{id:\d+}', [TermsController::class, 'edit'], 'admin.terms.edit'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post($taxonomy . '/{id:\d+}', [TermsController::class, 'update'], 'admin.terms.update'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post($taxonomy . '/{id:\d+}/translate', [TermActionsController::class, 'translate'], 'admin.terms.translate'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->get($taxonomy . '/{id:\d+}/delete', [TermActionsController::class, 'confirmDelete'], 'admin.terms.delete.confirm'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post($taxonomy . '/{id:\d+}/delete', [TermActionsController::class, 'delete'], 'admin.terms.delete'), Taxonomy::PERMISSION);

            // The sections: the order screen of the main menu, and their forms (TermRoutes).
            RequirePermission::on($admin->get('/sections', [SectionsController::class, 'index'], 'admin.sections'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post('/sections/order', [SectionsController::class, 'order'], 'admin.sections.order'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->get('/sections/new', [TermsController::class, 'create'], 'admin.sections.new'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post('/sections', [TermsController::class, 'store'], 'admin.sections.store'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->get('/sections/{id:\d+}', [TermsController::class, 'edit'], 'admin.sections.edit'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post('/sections/{id:\d+}', [TermsController::class, 'update'], 'admin.sections.update'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post('/sections/{id:\d+}/translate', [TermActionsController::class, 'translate'], 'admin.sections.translate'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->get('/sections/{id:\d+}/delete', [TermActionsController::class, 'confirmDelete'], 'admin.sections.delete.confirm'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post('/sections/{id:\d+}/delete', [TermActionsController::class, 'delete'], 'admin.sections.delete'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->get('/sections/{id:\d+}/order', [SectionArticlesController::class, 'show'], 'admin.sections.articles'), Taxonomy::PERMISSION);
            RequirePermission::on($admin->post('/sections/{id:\d+}/order', [SectionArticlesController::class, 'save'], 'admin.sections.articles.save'), Taxonomy::PERMISSION);

            $menus = '/menus/{location:[a-z][a-z0-9_]*}';
            RequirePermission::on($admin->get('/menus', [MenusController::class, 'index'], 'admin.menus'), MenuService::PERMISSION);
            RequirePermission::on($admin->get($menus, [MenusController::class, 'show'], 'admin.menus.show'), MenuService::PERMISSION);
            RequirePermission::on($admin->post($menus . '/order', [MenusController::class, 'order'], 'admin.menus.order'), MenuService::PERMISSION);
            RequirePermission::on($admin->get($menus . '/new', [MenuItemsController::class, 'create'], 'admin.menus.new'), MenuService::PERMISSION);
            RequirePermission::on($admin->post($menus, [MenuItemsController::class, 'store'], 'admin.menus.store'), MenuService::PERMISSION);
            RequirePermission::on($admin->get($menus . '/{id:\d+}', [MenuItemsController::class, 'edit'], 'admin.menus.edit'), MenuService::PERMISSION);
            RequirePermission::on($admin->post($menus . '/{id:\d+}', [MenuItemsController::class, 'update'], 'admin.menus.update'), MenuService::PERMISSION);
            RequirePermission::on($admin->get($menus . '/{id:\d+}/delete', [MenuItemsController::class, 'confirmDelete'], 'admin.menus.delete.confirm'), MenuService::PERMISSION);
            RequirePermission::on($admin->post($menus . '/{id:\d+}/delete', [MenuItemsController::class, 'delete'], 'admin.menus.delete'), MenuService::PERMISSION);

            RequirePermission::on($admin->get('/media', [MediaController::class, 'index'], 'admin.media'), MediaService::UPLOAD);
            RequirePermission::on($admin->post('/media', [MediaController::class, 'store'], 'admin.media.store'), MediaService::UPLOAD);
            RequirePermission::on($admin->get('/media/picker', [MediaController::class, 'picker'], 'admin.media.picker'), MediaService::UPLOAD);
            RequirePermission::on($admin->get('/media/{id:\d+}', [MediaItemController::class, 'edit'], 'admin.media.edit'), MediaService::UPLOAD);
            RequirePermission::on($admin->post('/media/{id:\d+}', [MediaItemController::class, 'update'], 'admin.media.update'), MediaService::UPLOAD);
            RequirePermission::on($admin->get('/media/{id:\d+}/delete', [MediaItemController::class, 'confirmDelete'], 'admin.media.delete.confirm'), MediaService::DELETE);
            RequirePermission::on($admin->post('/media/{id:\d+}/delete', [MediaItemController::class, 'delete'], 'admin.media.delete'), MediaService::DELETE);
            RequirePermission::on($admin->post('/media/move', [MediaBulkController::class, 'move'], 'admin.media.move'), MediaService::UPLOAD);
            RequirePermission::on($admin->post('/media/delete', [MediaBulkController::class, 'delete'], 'admin.media.delete.many'), MediaService::DELETE);
            RequirePermission::on($admin->get('/media/folders/new', [MediaFoldersController::class, 'create'], 'admin.media.folders.create'), MediaService::UPLOAD);
            RequirePermission::on($admin->post('/media/folders', [MediaFoldersController::class, 'store'], 'admin.media.folders.store'), MediaService::UPLOAD);
            RequirePermission::on($admin->get('/media/folders/{id:\d+}', [MediaFoldersController::class, 'edit'], 'admin.media.folders.edit'), MediaService::UPLOAD);
            RequirePermission::on($admin->post('/media/folders/{id:\d+}', [MediaFoldersController::class, 'update'], 'admin.media.folders.update'), MediaService::UPLOAD);
            RequirePermission::on($admin->get('/media/folders/{id:\d+}/delete', [MediaFoldersController::class, 'confirmDelete'], 'admin.media.folders.delete.confirm'), MediaService::DELETE);
            RequirePermission::on($admin->post('/media/folders/{id:\d+}/delete', [MediaFoldersController::class, 'delete'], 'admin.media.folders.delete'), MediaService::DELETE);
        }, RequireDefaultLocale::class, RequireAuthentication::class, AdminLocale::class);
    }

    public function adminMenu(AdminMenu $menu): void
    {
        $menu->add('admin.menu.dashboard', 'admin.dashboard', null, AdminMenu::DASHBOARD, [], 'layout-dashboard');
        $menu->add('admin.menu.media', 'admin.media', MediaService::UPLOAD, AdminMenu::MEDIA, [], 'image');
        $menu->add('admin.menu.settings', 'admin.settings', SettingsService::PERMISSION, AdminMenu::SETTINGS, [], 'settings');
        $menu->add('admin.menu.users', 'admin.users', 'users.manage', AdminMenu::USERS, [], 'users');
        $menu->add('admin.menu.audit', 'admin.audit', AuditLog::PERMISSION, AdminMenu::AUDIT, [], 'history');
    }
}
