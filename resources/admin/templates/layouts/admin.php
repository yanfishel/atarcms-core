<?php
/**
 * The admin page. Signed-in users get the shell: the sidebar (brand, grouped menu, rail toggle), the top bar with
 * the breadcrumbs (a page without a trail names itself), the inbox and the theme, content language and account menus (the account menu links the Profile), and the
 * page with its flash messages. The menu triggers show icons only; each is named by a title and an aria-label with
 * the same text. Every page ends with the quiet page footer (partials/footer): the AI guide link — also the <head>'s
 * rel="help" — for signed-in users, the year and the fishart mark for everyone. The rail toggle hangs on the
 * sidebar's edge (a child of the <aside>, outside the scrolling nav). The sidebar's group names are not headings (the
 * page's h1 comes first); each names its list. The toast stack, a polite live region, is always there (empty without
 * flashes) for scripts to fill. Guests (the login page, error pages) get a centred card without the sidebar.
 * The inline script in <head> is the only one: it applies the stored theme and sidebar
 * state before the stylesheet paints, and marks the page with the "js" class. <html lang dir> name the interface
 * language and its direction (langAttrs: a Hebrew interface is laid out right to left). <html data-accent> carries the admin colour
 * of the settings (the adminBrand helper; green when they cannot be read), whose palette admin.css's tokens apply.
 *
 * @var AtarCms\View\Template $this
 * @var string $content
 * @var string $title (optional) the page title, before the site name
 * @var list<array{0: string, 1?: string|null}> $breadcrumbs (optional) the trail after the dashboard as [label, url]; the last item is the current page
 * @var list<string> $scripts (optional) asset paths loaded as nonce'd module scripts on signed-in pages
 * @var list<string> $styles (optional) asset paths of extra stylesheets on signed-in pages
 * @var list<string> $themeStyles (optional) paths inside the active theme's assets/ of stylesheets loaded after $styles (the editor stylesheets of theme.php)
 * @var list<AtarCms\Admin\FlashMessage> $toasts (optional) this page's own messages, shown after the flashes (a refused form post of an order screen)
 * @var string $contentLocale (optional) the content language of the page (the item's or the list's), marked in the top bar's switch; else the remembered one
 */
$user = $this->user();
$trail = $breadcrumbs ?? [];
?>
<!doctype html>
<html<?= $this->langAttrs($this->locale()) ?> data-accent="<?= $this->e($this->adminBrand()->accent->value) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="htmx-config" content='{"includeIndicatorStyles":false,"allowEval":false}'>
    <title><?= $this->e(($title ?? $this->t('admin.title')) . ' · ' . $this->siteName()) ?></title>
    <script nonce="<?= $this->e($this->nonce()) ?>">try{var t=localStorage.getItem("admin.theme"),s=localStorage.getItem("admin.sidebar");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t;if(s==="rail"||s==="full")document.documentElement.dataset.sidebar=s}catch(e){}document.documentElement.classList.add("js")</script>
    <?php $brand = $this->adminBrand(); ?>
    <link rel="icon"<?= $brand->siteIcon ? '' : ' type="image/svg+xml"' ?> href="<?= $this->e($brand->markUrl) ?>">
    <link rel="stylesheet" href="<?= $this->e($this->asset('admin.css')) ?>">
    <script src="<?= $this->e($this->asset('vendor/htmx.min.js')) ?>" defer></script>
    <script type="module" src="<?= $this->e($this->asset('tooltip.js')) ?>" nonce="<?= $this->e($this->nonce()) ?>"></script>
    <?php if ($user !== null): ?>
    <link rel="help" href="<?= $this->e($this->url('admin.ai_guide')) ?>">
    <?php foreach ($styles ?? [] as $style): ?>
    <link rel="stylesheet" href="<?= $this->e($this->asset($style)) ?>">
    <?php endforeach ?>
    <?php foreach ($themeStyles ?? [] as $style): ?>
    <link rel="stylesheet" href="<?= $this->e($this->themeAsset($style)) ?>">
    <?php endforeach ?>
    <script type="module" src="<?= $this->e($this->asset('shell.js')) ?>" nonce="<?= $this->e($this->nonce()) ?>"></script>
    <?php foreach ($scripts ?? [] as $script): ?>
    <script type="module" src="<?= $this->e($this->asset($script)) ?>" nonce="<?= $this->e($this->nonce()) ?>"></script>
    <?php endforeach ?>
    <?php endif ?>
</head>
<?php if ($user === null): ?>
<body class="admin-guest">
<main class="admin-guest-main">
<?= $content ?>
</main>
<?= $this->partial('partials/footer') ?>
</body>
<?php else: ?>
<?php
$initials = $user->initials();
$flashes = [...$this->flashes(), ...($toasts ?? [])];
$locales = $this->siteLocales();
?>
<body class="admin-shell" hx-headers="<?= $this->e(json_encode(['X-CSRF-Token' => $this->csrfToken()], JSON_THROW_ON_ERROR)) ?>">
<a class="admin-skip" href="#main-content"><?= $this->e($this->t('admin.layout.skip')) ?></a>
<aside class="admin-sidebar" id="sidebar">
    <a class="admin-brand" href="<?= $this->e($this->url('admin.dashboard')) ?>" data-rail-title="<?= $this->e($this->siteName()) ?>">
        <?= trim($this->partial('partials/brand-mark')) ?>
        <span class="admin-brand-name"><?= $this->e($this->siteName()) ?></span>
    </a>
    <?php // The open phone drawer covers the burger: its own close button (shown on phones with JavaScript only). ?>
    <button type="button" class="admin-button admin-button-link admin-button-icon admin-drawer-close" title="<?= $this->e($this->t('admin.layout.close_menu')) ?>" data-drawer-dismiss><?= $this->icon('x', $this->t('admin.layout.close_menu')) ?></button>
    <nav class="admin-nav" aria-label="<?= $this->e($this->t('admin.layout.main_nav')) ?>">
        <?php foreach (AtarCms\Admin\AdminMenuGroup::of($this->adminMenu()) as $index => $group): ?>
        <div class="admin-nav-group">
            <?php if ($group->heading !== null): ?>
            <p class="admin-nav-heading" id="nav-group-<?= $this->e($index) ?>"><?= $this->e($this->t($group->heading)) ?></p>
            <ul class="admin-nav-list" aria-labelledby="nav-group-<?= $this->e($index) ?>">
            <?php else: ?>
            <ul class="admin-nav-list">
            <?php endif ?>
                <?php foreach ($group->items as $item): ?>
                <?php $label = $this->label($item->label); ?>
                <li><a class="admin-nav-link" href="<?= $this->e($this->url($item->route, $item->params)) ?>"<?= $this->isCurrentMenuItem($item) ? ' aria-current="page"' : '' ?> data-rail-title="<?= $this->e($label) ?>"><?= $this->icon($item->icon) ?><span class="admin-nav-label"><?= $this->e($label) ?></span></a></li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endforeach ?>
    </nav>
    <button type="button" class="admin-sidebar-toggle" data-sidebar-toggle title="<?= $this->e($this->t('admin.layout.collapse_menu')) ?>"><?= $this->icon('chevron-left', $this->t('admin.layout.collapse_menu')) ?></button>
</aside>
<div class="admin-backdrop" data-drawer-close hidden></div>
<div class="admin-main">
    <header class="admin-topbar">
        <button type="button" class="admin-burger admin-button admin-button-secondary admin-button-icon" data-drawer-open aria-controls="sidebar" aria-expanded="false" title="<?= $this->e($this->t('admin.layout.open_menu')) ?>"><?= $this->icon('menu', $this->t('admin.layout.open_menu')) ?></button>
        <nav class="admin-breadcrumbs" aria-label="<?= $this->e($this->t('admin.layout.breadcrumbs')) ?>">
            <ol>
                <?php if ($trail === []): ?>
                <li><span aria-current="page"><?= $this->e($title ?? $this->t('admin.dashboard.title')) ?></span></li>
                <?php else: ?>
                <li><a href="<?= $this->e($this->url('admin.dashboard')) ?>"><?= $this->e($this->t('admin.menu.dashboard')) ?></a></li>
                <?php foreach ($trail as $index => $crumb): ?>
                <?php if ($index === array_key_last($trail) || ($crumb[1] ?? null) === null): ?>
                <li><span<?= $index === array_key_last($trail) ? ' aria-current="page"' : '' ?>><?= $this->e($crumb[0]) ?></span></li>
                <?php else: ?>
                <li><a href="<?= $this->e($crumb[1]) ?>"><?= $this->e($crumb[0]) ?></a></li>
                <?php endif ?>
                <?php endforeach ?>
                <?php endif ?>
            </ol>
        </nav>
        <div class="admin-topbar-menus">
            <?php $unread = $this->unreadMessages(); ?>
            <?php if ($unread !== null): ?>
            <?php $name = $this->t('admin.layout.inbox', ['count' => $unread]); ?>
            <a class="admin-menu-trigger admin-inbox" href="<?= $this->e($this->url('admin.messages')) ?>" title="<?= $this->e($name) ?>" aria-label="<?= $this->e($name) ?>"><?= $this->icon('inbox') ?><?php if ($unread > 0): ?><span class="admin-inbox-count" aria-hidden="true"><?= $this->e($unread > 99 ? '99+' : (string) $unread) ?></span><?php endif ?></a>
            <?php endif ?>
            <details class="admin-menu admin-theme-menu" data-menu>
                <?php $name = $this->t('admin.theme.title') . ': ' . $this->t('admin.theme.system'); ?>
                <summary class="admin-menu-trigger" title="<?= $this->e($name) ?>" aria-label="<?= $this->e($name) ?>" data-theme-trigger data-theme-title="<?= $this->e($this->t('admin.theme.title')) ?>">
                    <?php foreach (['light' => 'sun', 'dark' => 'moon', 'system' => 'monitor'] as $choice => $icon): ?>
                    <span data-theme-icon="<?= $this->e($choice) ?>"<?= $choice === 'system' ? '' : ' hidden' ?>><?= $this->icon($icon) ?></span>
                    <?php endforeach ?>
                </summary>
                <div class="admin-menu-panel">
                    <?php // The language menu's items around visually hidden radios; admin.css shows the check on the checked one. ?>
                    <fieldset class="admin-menu-list admin-theme-switch" aria-label="<?= $this->e($this->t('admin.theme.title')) ?>">
                        <?php foreach (['light' => 'sun', 'dark' => 'moon', 'system' => 'monitor'] as $choice => $icon): ?>
                        <label class="admin-menu-item admin-theme-option"><input type="radio" name="theme" value="<?= $this->e($choice) ?>" data-theme-choice<?= $choice === 'system' ? ' checked' : '' ?>><?= $this->icon($icon) ?><?= $this->e($this->t('admin.theme.' . $choice)) ?><?= $this->icon('check') ?></label>
                        <?php endforeach ?>
                    </fieldset>
                </div>
            </details>
            <?php if (count($locales) > 1): ?>
            <?= $this->partial('partials/content-locale', ['current' => $contentLocale ?? $this->contentLocale(), 'locales' => $locales]) ?>
            <?php endif ?>
            <details class="admin-menu" data-menu>
                <?php $name = $this->t('admin.layout.account') . ': ' . $user->displayName(); ?>
                <summary class="admin-menu-trigger" title="<?= $this->e($name) ?>" aria-label="<?= $this->e($name) ?>"><span class="admin-avatar admin-role admin-role-<?= $this->e($user->role) ?>" aria-hidden="true"><?php if ($user->avatarVersion !== null): ?><img class="admin-avatar-image" src="<?= $this->e($this->url('admin.avatar', ['id' => (string) $user->id]) . '?v=' . $user->avatarVersion) ?>" alt="" width="36" height="36"><?php else: ?><?= $this->e($initials) ?><?php endif ?></span></summary>
                <div class="admin-menu-panel admin-menu-panel-account">
                    <div class="admin-user-card">
                        <p class="admin-user-name"><?= $this->e($user->displayName()) ?></p>
                        <p class="admin-user-meta"><?= $this->e($user->email) ?></p>
                        <p class="admin-user-meta"><?= $this->e($this->label($this->roleLabel($user->role))) ?></p>
                    </div>
                    <a class="admin-menu-item admin-menu-item-icon" href="<?= $this->e($this->url('admin.profile')) ?>"><?= $this->icon('users') ?><?= $this->e($this->t('admin.layout.profile')) ?></a>
                    <hr class="admin-menu-separator">
                    <form class="admin-signout" method="post" action="<?= $this->e($this->url('admin.logout')) ?>">
                        <?= $this->csrfField() ?>
                        <button type="submit" class="admin-menu-item admin-menu-item-icon"><?= $this->icon('log-out') ?><?= $this->e($this->t('admin.layout.sign_out')) ?></button>
                    </form>
                </div>
            </details>
        </div>
    </header>
    <main class="admin-content" id="main-content" tabindex="-1">
        <?php if ($flashes === []): ?>
        <div class="admin-toasts" data-toasts aria-live="polite"></div>
        <?php else: ?>
        <div class="admin-toasts" data-toasts aria-live="polite">
            <?php foreach ($flashes as $flash): ?>
            <?= $this->partial('partials/toast', ['type' => $flash->type, 'text' => $this->t($flash->key, $flash->params), 'form' => $flash->form]) ?>
            <?php endforeach ?>
        </div>
        <?php endif ?>
        <div class="admin-visually-hidden" role="status" data-toast-announcer></div>
<?= $content ?>
    </main>
    <?= trim($this->partial('partials/footer')) ?>
</div>
</body>
<?php endif ?>
</html>
