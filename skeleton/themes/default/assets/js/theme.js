// The site's small scripts (loaded as a module, after the page): the light / dark / system choice of the theme menu,
// remembered in localStorage (the inline bootstrap in <head> applies it before the first paint), and the header's
// dropdowns and the chat button's panel, which close on Escape and on a click elsewhere. Without JavaScript the theme
// follows the system and they are plain <details>.

const KEY = 'atar-theme';
const root = document.documentElement;

function store(choice) {
    try {
        if (choice === 'system') {
            localStorage.removeItem(KEY);
        } else {
            localStorage.setItem(KEY, choice);
        }
    } catch {
        // Storage may be off (a private window): the choice holds for this page only.
    }
}

function apply(choice) {
    if (choice === 'light' || choice === 'dark') {
        root.dataset.theme = choice;
    } else {
        delete root.dataset.theme;
    }
    for (const button of document.querySelectorAll('[data-theme-choice]')) {
        button.ariaPressed = String(button.dataset.themeChoice === choice);
    }
    const current = document.querySelector('[data-theme-current]');
    if (current !== null) {
        for (const icon of current.querySelectorAll('[data-theme-icon]')) {
            icon.hidden = icon.dataset.themeIcon !== choice;
        }
    }
}

apply(root.dataset.theme ?? 'system');

for (const button of document.querySelectorAll('[data-theme-choice]')) {
    button.addEventListener('click', () => {
        const choice = button.dataset.themeChoice;
        store(choice);
        apply(choice);
        const menu = button.closest('details');
        if (menu !== null) {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        }
    });
}

const dropdowns = [...document.querySelectorAll('details.dropdown, details.chat')];

document.addEventListener('click', (event) => {
    for (const menu of dropdowns) {
        if (menu.open && !menu.contains(event.target)) {
            menu.open = false;
        }
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }
    for (const menu of dropdowns) {
        if (menu.open) {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        }
    }
});

for (const menu of dropdowns) {
    menu.addEventListener('toggle', () => {
        if (!menu.open) {
            return;
        }
        for (const other of dropdowns) {
            if (other !== menu) {
                other.open = false;
            }
        }
    });
}
