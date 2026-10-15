// Tooltips: the title of any element shows as the page's own tip instead of the browser's box, on hover (mouse and
// pen, after a short delay; at once when moving on from one tip to the next) and on keyboard focus. One shared
// element (#tooltip, a manual popover: in the top layer it is never clipped by overflow and shows above an open modal
// dialog) holds the text, through textContent only. CSS anchor positioning places it: the trigger carries
// data-tooltip-anchor while its tip shows, and the stylesheet puts #tooltip above it, flipped below or sideways near
// an edge (it hides at once, so it never fades out away from its anchor). While the tip shows, the trigger's title
// waits in data-tooltip-title (so the browser's box does not show as well) and comes back when it hides; a trigger
// named only by its title gets the same text as aria-label for that time, so its accessible name never disappears, and
// any other trigger is described by the tip. Pointer leave, focus leave, Escape (which then closes only the tip, not a
// dialog or menu), any other key but Tab and the modifiers, a menu or popover opening, a press, scrolling under the
// pointer, resizing, an htmx swap, a closing dialog and leaving the page hide it. A tip dismissed under the pointer
// (a press, a key, scrolling) keeps the title parked until the pointer leaves, so the browser's box does not take its
// place. Skipped: CKEditor's UI (its own tooltips), <option>, <iframe> and anything inside [data-no-tooltip], which
// keep the native title. Without the Popover API or anchor positioning (or without JavaScript) nothing happens and
// the browser shows titles as usual. The same file serves the admin (resources/admin/assets) and the skeleton theme
// (assets/js), byte for byte; each stylesheet styles #tooltip.

const SHOW_MS = 300; // the hover delay
const CHAIN_MS = 500; // a tip that hid this recently: the next one shows at once
const SKIP = '.ck, option, iframe, [data-no-tooltip]';
const QUIET_KEYS = ['Tab', 'Shift', 'Control', 'Alt', 'Meta', 'AltGraph', 'CapsLock'];

const supported = typeof HTMLElement.prototype.showPopover === 'function'
    && CSS.supports('anchor-name: --a')
    && CSS.supports('position-area: block-start');

if (supported) {
    const tip = document.createElement('div');
    tip.id = 'tooltip';
    tip.popover = 'manual';
    tip.role = 'tooltip';
    document.body.append(tip);

    let holder = null; // the element whose title is parked: its tip shows, or it was dismissed under the pointer
    let shown = false; // the tip shows for the holder
    let source = ''; // what showed it: "pointer" or "focus"
    let named = false; // the holder's aria-label is its title, for the time it is parked
    let described = false; // the holder's description is the tip, for that time
    let pending = null; // the element hovered, waiting for the delay
    let timer = 0;
    let hiddenAt = -Infinity;

    // The element with a title that shows a tip for this target, if any. A parked holder has an empty title, so the
    // search stops there.
    const triggerOf = (target) => {
        const element = target instanceof Element ? target.closest('[title]') : null;

        return element instanceof HTMLElement && element.title.trim() !== '' && element.closest(SKIP) === null ? element : null;
    };

    // Whether the element is named by something besides its title: an aria-label(ledby), a <label>, its visible text,
    // an image's alternative text, a labelled icon or a button's value.
    const hasName = (element) => element.hasAttribute('aria-label')
        || element.hasAttribute('aria-labelledby')
        || (element.labels?.length ?? 0) > 0
        || element.innerText.trim() !== ''
        || element.querySelector('img:not([alt=""]), [role="img"][aria-label]') !== null
        || (element instanceof HTMLInputElement && ['button', 'submit', 'reset'].includes(element.type) && element.value !== '');

    // The element's own name, as far as it matters here: a tip that repeats it describes nothing.
    const nameOf = (element) => (element.ariaLabel ?? element.innerText.trim())
        || (element.querySelector('[role="img"][aria-label]')?.ariaLabel ?? '').trim();

    const cancel = () => {
        window.clearTimeout(timer);
        pending = null;
    };

    // Gives the holder its title and its own accessible name back.
    function release() {
        const element = holder;
        holder = null;
        // A script may have given it a new title meanwhile (the admin's theme menu): that one stays.
        if (element.title === '') {
            element.title = element.dataset.tooltipTitle ?? '';
        }
        delete element.dataset.tooltipTitle;
        delete element.dataset.tooltipAnchor;
        if (named) {
            element.ariaLabel = null;
        }
        if (described) {
            element.ariaDescribedByElements = null;
        }
        named = false;
        described = false;
    }

    // Hides the tip; with park, a holder still under the pointer keeps its title parked until the pointer leaves it.
    function hide(park = false) {
        cancel();
        if (holder === null) {
            return;
        }
        if (shown) {
            if (tip.matches(':popover-open')) {
                tip.hidePopover();
            }
            delete holder.dataset.tooltipAnchor;
            shown = false;
            hiddenAt = performance.now();
        }
        if (!park || !holder.isConnected || !holder.matches(':hover')) {
            release();
        }
    }

    // A holder taken out of the page (another script replaced it) lets its tip go.
    const dropGone = () => {
        if (holder !== null && !holder.isConnected) {
            hide();
        }
    };

    function show(element, by) {
        cancel();
        if (element === holder && shown) {
            return;
        }
        if (element !== holder) {
            hide();
            const text = element.title.trim();
            if (!element.isConnected || text === '') {
                return;
            }
            holder = element;
            element.dataset.tooltipTitle = element.title;
            if (!hasName(element)) {
                element.ariaLabel = text;
                named = true;
            } else if (!element.hasAttribute('aria-describedby') && 'ariaDescribedByElements' in element
                && text !== nameOf(element)) {
                element.ariaDescribedByElements = [tip];
                described = true;
            }
        } else if (element.title !== '') {
            // A parked holder whose title a script changed meanwhile: the new one shows.
            element.dataset.tooltipTitle = element.title;
        }
        element.title = '';
        element.dataset.tooltipAnchor = '';
        source = by;
        tip.textContent = (element.dataset.tooltipTitle ?? '').trim();
        if (!tip.isConnected) {
            document.body.append(tip);
        }
        tip.showPopover();
        shown = true;
    }

    document.addEventListener('pointerover', (event) => {
        if (event.pointerType === 'touch') {
            return;
        }
        dropGone();
        const element = triggerOf(event.target);
        // Inside the holder nothing changes, unless a descendant has a title of its own: that is a new trigger.
        if (element === null || element === pending) {
            return;
        }
        cancel();
        if (shown || performance.now() - hiddenAt < CHAIN_MS) {
            show(element, 'pointer');

            return;
        }
        pending = element;
        timer = window.setTimeout(() => show(element, 'pointer'), SHOW_MS);
    });

    document.addEventListener('pointerout', (event) => {
        if (event.pointerType === 'touch') {
            return;
        }
        const inside = (element) => element !== null && element.contains(event.target)
            && !(event.relatedTarget instanceof Node && element.contains(event.relatedTarget));
        if (inside(pending)) {
            cancel();
        }
        if (inside(holder) && !(shown && source === 'focus')) {
            hide();
        }
    });

    // Keyboard focus shows the focused element's own title at once; a click's focus (not :focus-visible) does not.
    document.addEventListener('focusin', (event) => {
        dropGone();
        const element = triggerOf(event.target);
        if (element !== null && element === event.target && element.matches(':focus-visible')) {
            show(element, 'focus');
        }
    });
    document.addEventListener('focusout', (event) => {
        if (shown && event.target === holder && source === 'focus') {
            hide();
        }
    });

    // Escape closes the tip alone when one shows; else it goes on to the dialog or the menu as usual. Any other key
    // but Tab and the modifiers (Enter or Space opening a menu, typing) hides it too, and goes on.
    window.addEventListener('keydown', (event) => {
        dropGone();
        if (!shown) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            hide(true);
        } else if (!QUIET_KEYS.includes(event.key)) {
            hide(true);
        }
    }, true);

    // A <details> menu or another popover opening or closing (toggle does not bubble: captured).
    document.addEventListener('toggle', (event) => {
        if (event.target !== tip) {
            dropGone();
            hide(true);
        }
    }, true);

    // A focused trigger's tip follows it while the page scrolls (it is anchored); a hovered one leaves the pointer.
    document.addEventListener('scroll', () => {
        dropGone();
        if (shown && source === 'pointer') {
            hide(true);
        }
        cancel();
    }, { capture: true, passive: true });
    // A touch press never parks the title: no touch pointerout would give it back.
    document.addEventListener('pointerdown', (event) => hide(event.pointerType !== 'touch'), true);
    document.addEventListener('close', () => hide(), true);
    document.addEventListener('htmx:beforeSwap', () => hide());
    window.addEventListener('resize', () => hide());
    window.addEventListener('pagehide', () => hide());
}
