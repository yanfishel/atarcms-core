// The accessibility toolbar (partials/a11y.php): the round button opens a panel of display options; each option is a
// class on <html> ("a11y-" and the option's name; the text size a11y-text-1..3) that site.css styles. The choice is
// remembered in localStorage as a list of those classes (the inline bootstrap in <head> applies it before the first
// paint, so nothing flashes on the next page). The panel is not modal, so it traps nothing: Tab moves on past it;
// Escape, the close button, the button itself or a click elsewhere close it; after Escape or the close button the
// focus goes back to the button when it was in the panel (a click elsewhere leaves the focus where it went). The
// reading guide is a fixed band that follows the pointer: its position is a custom property in a constructable
// stylesheet (no style attribute, which the Content Security Policy refuses).

const KEY = 'atar-a11y';
const OPTIONS = ['spacing', 'font', 'contrast', 'grayscale', 'invert', 'links', 'focus', 'cursor', 'still', 'guide', 'no-images'];
const SIZES = [1, 1.15, 1.3, 1.5]; // the text size steps: a11y-text-1..3 scale the root font size (site.css)
const root = document.documentElement;
const toolbar = document.querySelector('[data-a11y]');

function read() {
    try {
        const stored = JSON.parse(localStorage.getItem(KEY) ?? '[]');

        return Array.isArray(stored) ? stored : [];
    } catch {
        return [];
    }
}

function store() {
    const classes = [...root.classList].filter((name) => name.startsWith('a11y-'));
    try {
        if (classes.length === 0) {
            localStorage.removeItem(KEY);
        } else {
            localStorage.setItem(KEY, JSON.stringify(classes));
        }
    } catch {
        // Storage may be off (a private window): the choice holds for this page only.
    }
}

if (toolbar !== null) {
    const trigger = toolbar.querySelector('[data-a11y-trigger]');
    const panel = toolbar.querySelector('[data-a11y-panel]');
    const sizeValue = toolbar.querySelector('[data-a11y-size-value]');
    const percent = new Intl.NumberFormat(root.lang || undefined, {style: 'percent', maximumFractionDigits: 0});

    // Classes stored by an earlier version (or by hand) that this one does not know are dropped.
    const known = new Set([...OPTIONS.map((option) => 'a11y-' + option), ...SIZES.slice(1).map((size, index) => 'a11y-text-' + (index + 1))]);
    for (const name of [...root.classList]) {
        if (name.startsWith('a11y-') && !known.has(name)) {
            root.classList.remove(name);
        }
    }
    for (const name of read()) {
        if (known.has(name)) {
            root.classList.add(name);
        }
    }

    const sizeStep = () => SIZES.findLastIndex((size, index) => index === 0 || root.classList.contains('a11y-text-' + index));

    // The buttons show the current state: pressed options, the size step and its limits.
    const render = () => {
        for (const button of toolbar.querySelectorAll('[data-a11y-option]')) {
            button.ariaPressed = String(root.classList.contains('a11y-' + button.dataset.a11yOption));
        }
        const step = sizeStep();
        sizeValue.textContent = percent.format(SIZES[step]);
        for (const button of toolbar.querySelectorAll('[data-a11y-size]')) {
            const next = step + Number(button.dataset.a11ySize);
            // At a limit the button stays focusable (a disabled one would drop the focus) and does nothing.
            button.ariaDisabled = String(next < 0 || next >= SIZES.length);
        }
    };

    // The reading guide: the band's position follows the pointer, at most once a frame.
    const guide = new CSSStyleSheet();
    document.adoptedStyleSheets = [...document.adoptedStyleSheets, guide];
    let frame = 0;
    let pointerY = -1000;
    const follow = (event) => {
        if (event.pointerType === 'touch') {
            return;
        }
        pointerY = event.clientY;
        if (frame === 0) {
            frame = requestAnimationFrame(() => {
                frame = 0;
                guide.replaceSync(`:root { --a11y-guide-y: ${Math.round(pointerY)}px; }`);
            });
        }
    };
    const watchPointer = () => {
        if (root.classList.contains('a11y-guide')) {
            document.addEventListener('pointermove', follow, {passive: true});
        } else {
            document.removeEventListener('pointermove', follow);
            guide.replaceSync('');
        }
    };

    const isOpen = () => !panel.hidden;
    const open = () => {
        panel.hidden = false;
        trigger.ariaExpanded = 'true';
        panel.focus();
    };
    // returnFocus: Escape and the close button give the focus back to the button when it was in the panel.
    const close = (returnFocus) => {
        if (!isOpen()) {
            return;
        }
        const inside = panel.contains(document.activeElement);
        panel.hidden = true;
        trigger.ariaExpanded = 'false';
        if (returnFocus && inside) {
            trigger.focus();
        }
    };

    trigger.addEventListener('click', () => (isOpen() ? close(false) : open()));
    toolbar.querySelector('[data-a11y-close]').addEventListener('click', () => close(true));

    for (const button of toolbar.querySelectorAll('[data-a11y-option]')) {
        button.addEventListener('click', () => {
            root.classList.toggle('a11y-' + button.dataset.a11yOption);
            store();
            render();
            watchPointer();
        });
    }
    for (const button of toolbar.querySelectorAll('[data-a11y-size]')) {
        button.addEventListener('click', () => {
            const next = sizeStep() + Number(button.dataset.a11ySize);
            if (next < 0 || next >= SIZES.length) {
                return;
            }
            for (let index = 1; index < SIZES.length; index++) {
                root.classList.toggle('a11y-text-' + index, index === next);
            }
            store();
            render();
        });
    }
    toolbar.querySelector('[data-a11y-reset]').addEventListener('click', () => {
        for (const name of [...root.classList]) {
            if (name.startsWith('a11y-')) {
                root.classList.remove(name);
            }
        }
        store();
        render();
        watchPointer();
    });

    // On window, after tooltip.js (loaded first): Escape that hides a tip goes no further.
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen() && !event.defaultPrevented) {
            close(true);
        }
    });
    document.addEventListener('click', (event) => {
        if (isOpen() && !toolbar.contains(event.target)) {
            close(false);
        }
    });

    render();
    watchPointer();
}
