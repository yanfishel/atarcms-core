// The admin shell: the theme switch, the top-bar menus, the sidebar rail, the phone drawer and the flash messages
// (toasts). The inline script in the layout's <head> has already applied the stored theme and sidebar state before
// the first paint; this module only reacts to the user. Without JavaScript the sidebar is a plain list of links above
// the page, the theme menu and the toggles are hidden (admin.css), the other menus open as plain <details>, and
// flash messages stay where they are.
const root = document.documentElement;

// localStorage may be unavailable (private windows, blocked site data): the choice then lasts for this page only.
function read(key) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function store(key, value) {
    try {
        if (value === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, value);
        }
    } catch {
        // Nothing to do: see above.
    }
}

// Theme: "light", "dark", or no attribute for the device setting ("system"). The top-bar trigger shows the current
// choice: the server renders the device setting, so its icon, tooltip and accessible name are set here, from the
// title the server printed and the radio's own label.
const theme = read('admin.theme');
const themeIcons = document.querySelectorAll('[data-theme-icon]');
const themeTrigger = document.querySelector('[data-theme-trigger]');
const showTheme = (input) => {
    for (const icon of themeIcons) {
        icon.hidden = icon.dataset.themeIcon !== input.value;
    }
    if (themeTrigger instanceof HTMLElement) {
        const name = `${themeTrigger.dataset.themeTitle ?? ''}: ${input.closest('label')?.textContent.trim() ?? ''}`;
        themeTrigger.title = name;
        themeTrigger.ariaLabel = name;
    }
};
for (const input of document.querySelectorAll('[data-theme-choice]')) {
    input.checked = input.value === (theme === 'light' || theme === 'dark' ? theme : 'system');
    if (input.checked) {
        showTheme(input);
    }
    input.addEventListener('change', () => {
        if (input.value === 'light' || input.value === 'dark') {
            root.dataset.theme = input.value;
            store('admin.theme', input.value);
        } else {
            delete root.dataset.theme;
            store('admin.theme', null);
        }
        showTheme(input);
    });
}

// The top-bar menus are <details> elements (they open and close without JavaScript too). Opening one closes the
// others; a click outside or Escape closes the open one.
const menus = [...document.querySelectorAll('[data-menu]')];
for (const menu of menus) {
    menu.addEventListener('toggle', () => {
        if (menu.open) {
            for (const other of menus) {
                if (other !== menu) {
                    other.open = false;
                }
            }
        }
    });
}
document.addEventListener('click', (event) => {
    for (const menu of menus) {
        if (menu.open && !(event.target instanceof Node && menu.contains(event.target))) {
            menu.open = false;
        }
    }
});

// Escape closes an open menu (focus returns to its trigger), else the drawer.
function closeMenu() {
    const open = menus.find((menu) => menu.open);
    if (open === undefined) {
        return false;
    }
    open.open = false;
    open.querySelector('summary')?.focus();

    return true;
}

// The rail (icons only) is the default below 1200 px; the toggle stores an explicit choice either way. Below 768 px the
// sidebar is the drawer, with its labels. The brand and the menu links carry their name in data-rail-title: it becomes
// their title (a tip) only where the label does not show in full, on the rail or cut short.
const narrow = window.matchMedia('(max-width: 1199px)');
const phone = window.matchMedia('(max-width: 767px)');
const toggle = document.querySelector('[data-sidebar-toggle]');
const isRail = () => root.dataset.sidebar === 'rail' || (root.dataset.sidebar !== 'full' && narrow.matches);
const isCut = (link) => [...link.querySelectorAll('.admin-nav-label, .admin-brand-name')]
    .some((label) => label.scrollWidth > label.clientWidth);
const syncToggle = () => {
    if (toggle !== null) {
        toggle.ariaPressed = String(isRail());
    }
    const rail = isRail() && !phone.matches;
    for (const link of document.querySelectorAll('[data-rail-title]')) {
        link.title = rail || isCut(link) ? link.dataset.railTitle : '';
    }
};
toggle?.addEventListener('click', () => {
    const next = isRail() ? 'full' : 'rail';
    root.dataset.sidebar = next;
    store('admin.sidebar', next);
    syncToggle();
});
narrow.addEventListener('change', syncToggle);
syncToggle();

// The drawer (below 768 px): opened by the burger, closed by its × button, the backdrop, Escape or following a link. It slides only
// once the burger was used ("admin-drawer-animated"), so a window crossing 768 px moves the sidebar without an
// animation. While it is open, Tab and Shift+Tab cycle through its links and buttons (the page lies behind the backdrop).
const burger = document.querySelector('[data-drawer-open]');
const backdrop = document.querySelector('[data-drawer-close]');
const sidebar = document.getElementById('sidebar');
const isOpen = () => root.classList.contains('admin-drawer-open');
// The page behind the open drawer (the skip link, the top bar but its burger, the content): inert while the drawer is
// open on a phone. The burger stays usable as a toggle beside the drawer's own close button.
const behind = [...document.querySelectorAll('.admin-skip, .admin-breadcrumbs, .admin-topbar-menus, .admin-content')];

function setDrawer(open) {
    const wasOpen = isOpen();
    root.classList.toggle('admin-drawer-open', open);
    for (const element of behind) {
        element.inert = open && phone.matches;
    }
    if (burger !== null) {
        burger.ariaExpanded = String(open);
    }
    if (backdrop !== null) {
        backdrop.hidden = !open;
    }
    if (open) {
        sidebar?.querySelector('a')?.focus();
    } else if (wasOpen && phone.matches) {
        burger?.focus();
    }
}

burger?.addEventListener('click', () => {
    root.classList.add('admin-drawer-animated');
    setDrawer(!isOpen());
});
backdrop?.addEventListener('click', () => setDrawer(false));
// The open drawer covers the burger: the × in its brand band closes it too (the focus goes back to the burger).
const dismiss = document.querySelector('[data-drawer-dismiss]');
dismiss?.addEventListener('click', () => setDrawer(false));
sidebar?.addEventListener('click', (event) => {
    if (isOpen() && event.target instanceof Element && event.target.closest('a') !== null) {
        setDrawer(false);
    }
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !closeMenu() && isOpen()) {
        setDrawer(false);
    }
});

// The links and buttons of the drawer that can take focus now (the rail toggle is hidden on phones).
const drawerControls = () => [...(sidebar?.querySelectorAll('a[href], button:not([disabled])') ?? [])]
    .filter((control) => control.getClientRects().length > 0);
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab' || !isOpen() || !phone.matches) {
        return;
    }
    const controls = drawerControls();
    if (controls.length === 0) {
        return;
    }
    const first = controls[0];
    const last = controls[controls.length - 1];
    const inside = sidebar.contains(document.activeElement);
    if (event.shiftKey && (!inside || document.activeElement === first)) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (!inside || document.activeElement === last)) {
        event.preventDefault();
        first.focus();
    }
});

// A drawer left open while the window grows past the phone width would otherwise stay marked open; crossing the
// width either way moves the sidebar at once.
phone.addEventListener('change', () => {
    root.classList.remove('admin-drawer-animated');
    if (!phone.matches) {
        setDrawer(false);
    }
    syncToggle();
});

// Toasts: a success message leaves after six seconds (hovering or focusing it pauses the countdown); an error
// stays until it is closed. Closing with the button moves focus to the page (or into the dialog the toast sits in), so it
// is not lost with the toast. Toasts that scripts add later (an htmx upload) are wired the same way: every toast
// stack is observed.
const TOAST_MS = 6000;
const main = document.getElementById('main-content');

function wireToast(toast) {
    let timer = 0;
    const close = () => toast.remove();
    const pause = () => window.clearTimeout(timer);
    const start = () => {
        pause();
        if (toast.isConnected && toast.classList.contains('admin-toast-success') && !toast.matches(':hover, :focus-within')) {
            timer = window.setTimeout(close, TOAST_MS);
        }
    };
    toast.addEventListener('mouseenter', pause);
    toast.addEventListener('mouseleave', start);
    toast.addEventListener('focusin', pause);
    toast.addEventListener('focusout', start);
    toast.querySelector('[data-toast-close]')?.addEventListener('click', () => {
        // A dialog itself takes no focus: the first control of the picker's body (its search field) does.
        const home = toast.closest('dialog')?.querySelector('[data-media-dialog-body] input:not([type="hidden"]), [data-media-dialog-body] button') ?? main;
        pause();
        close();
        home?.focus();
    });
    start();
}

for (const toast of document.querySelectorAll('[data-toast]')) {
    wireToast(toast);
}

// A live region announces changes only, so the toasts the server rendered are not read out on their own: their texts
// (errors first) go into the layout's empty status region once the page has settled. The toasts stay where they are.
const ANNOUNCE_MS = 500;
const announcer = document.querySelector('[data-toast-announcer]');
const atLoad = [...document.querySelectorAll('[data-toasts] > [data-toast]')]
    .sort((a, b) => Number(b.matches('.admin-toast-error')) - Number(a.matches('.admin-toast-error')))
    .map((toast) => toast.querySelector('.admin-toast-text')?.textContent ?? '')
    .filter((text) => text !== '');
if (announcer !== null && atLoad.length > 0) {
    window.setTimeout(() => {
        announcer.textContent = atLoad.join(' ');
    }, ANNOUNCE_MS);
}
for (const stack of document.querySelectorAll('[data-toasts]')) {
    new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node instanceof HTMLElement && node.matches('[data-toast]')) {
                    wireToast(node);
                }
            }
        }
    }).observe(stack, { childList: true });
}
