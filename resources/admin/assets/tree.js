// Drag-and-drop for the admin tree editor (menus, pages, terms, sections). The server-rendered form works without
// JavaScript: its hidden "tree" input holds the stored order as JSON, and each arrow button submits it with a
// "move" value that the server applies. This module lets editors drag items by their handle (SortableJS), rewrites
// the hidden input after every drop and saves it in the background a moment later (one request at a time, to the
// form's own action, with the form's CSRF field); the answer's version goes into the "version" input, so the next
// save and the arrow buttons send it. The result is a toast in the page's stack (a live region), cloned from the
// form's <template>s: "Order saved.", or the reason with a "Reload the page" button after a failure (a stale page, an
// expired session); a new result replaces the form's previous toast. It also disables the arrow buttons that would
// change nothing (on load and after every drop; the server cannot know the order after a drag, so it renders them
// all enabled), and makes the tree an accordion: an item's toggle (its number of children and a chevron, before its
// moves) or a click on the free part of its row shows or hides its children (see OPEN_KEY).
import Sortable from './vendor/sortable-1.15.7.esm.js';

const LIST = '[data-tree-list]';

// The pause after a drop before the tree is saved: a few quick drags make one request.
const SAVE_DELAY = 500;

// An empty nested list (an item without children) takes no room, also during a drag: a list that grew when a drag
// starts would shift the rows below it under the pointer. SortableJS drops into an empty list when the pointer comes
// this close to it (in pixels), i.e. near the bottom edge of the item's row.
const EMPTY_INSERT_THRESHOLD = 16;

// The accordion: under .js the stylesheet hides the children of every item without admin-tree-open from the first
// paint (collapsed by default); the items opened with their toggle or row are remembered per screen and language (the
// form's action and locale) in localStorage under this prefix, and tree-open.js opens them again before the first
// paint (this module runs too late for that: opening them here would move the rows below). During a drag, resting on
// a collapsed item this long (in ms) opens it, so an item can be dropped among its children; a drop into an item opens
// it.
const OPEN_KEY = 'admin.tree.open:';
const OPEN_DELAY = 600;

// What a click on a row hits without toggling its item: the row's own controls and anything else focusable.
const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [tabindex], [data-tree-handle]';

const childList = (item) => item.querySelector(':scope > ' + LIST);

const itemsOf = (list) => (list === null ? [] : [...list.children].filter((child) => child.matches('[data-id]')));

const serialize = (list) => itemsOf(list).map((item) => ({
    id: Number(item.dataset.id),
    children: serialize(childList(item)),
}));

// Nesting level of a list: 0 for the top level, 1 for the lists inside its items, and so on.
const depthOf = (list, root) => {
    let depth = 0;
    for (let node = list; node !== null && node !== root; node = node.parentElement) {
        if (node.matches(LIST)) {
            depth += 1;
        }
    }
    return depth;
};

// Levels an item occupies: 1 for a leaf, 1 + its deepest child otherwise.
const height = (item) => 1 + Math.max(0, ...itemsOf(childList(item)).map(height));

// A pinned item (a fixed item of the main menu on the Sections screen) stays at the top level and holds nothing.
const pinned = (item) => item !== undefined && item.matches('[data-tree-pinned]');

document.querySelectorAll('[data-tree-form]').forEach((form, formIndex) => {
    const input = form.querySelector('[data-tree-input]');
    const version = form.querySelector('[data-tree-version]');
    const savedToast = form.querySelector('template[data-tree-saved]');
    const failedToast = form.querySelector('template[data-tree-failed]');
    const toasts = document.querySelector('[data-toasts]');
    const root = form.querySelector(LIST);
    if (input === null || version === null || savedToast === null || failedToast === null || toasts === null || root === null) {
        return;
    }
    const maxDepth = Number(form.dataset.maxDepth) || 1;
    const update = () => {
        input.value = JSON.stringify(serialize(root));
    };
    // As TreeOrder::move() on the server: "up"/"down" swap with a neighbour, "indent" makes the item the last child
    // of the one above (within the depth limit), "outdent" moves it after its parent.
    const syncMoves = () => {
        form.querySelectorAll('[data-id]').forEach((item) => {
            const siblings = itemsOf(item.parentElement);
            const index = siblings.indexOf(item);
            const depth = depthOf(item.parentElement, root);
            const useless = {
                up: index === 0,
                down: index === siblings.length - 1,
                outdent: depth === 0,
                indent: index === 0 || depth + 1 + height(item) > maxDepth || pinned(siblings[index - 1]),
            };
            item.querySelector(':scope > .admin-tree-row').querySelectorAll('button[name="move"]').forEach((button) => {
                button.disabled = useless[button.value.split(':')[1]] === true;
            });
        });
    };

    // The accordion's state: the open items' ids (tree-open.js has opened them), written after every change (only
    // items still on the page, so ids of deleted items drop out).
    const storageKey = OPEN_KEY + form.getAttribute('action') + ':' + form.elements.namedItem('locale')?.value;
    const remember = () => {
        try {
            localStorage.setItem(storageKey, JSON.stringify([...form.querySelectorAll('.admin-tree-open')].map((item) => Number(item.dataset.id))));
        } catch {
            // Storage blocked: the state lasts as long as the page.
        }
    };
    // The toggles show the number of children the server rendered ("2 subsections"); a drag changes it. A script
    // cannot translate: the form carries the message's plural forms in the interface language ("#" for the number) by
    // Intl.PluralRules category, and the page's lang picks one (and formats the number like the server's ICU); a
    // category the server did not send falls back to any form it did (Russian has no "other" for whole numbers).
    let counts = {};
    try {
        counts = JSON.parse(form.dataset.treeCounts ?? '{}');
    } catch {
        counts = {};
    }
    const lang = document.documentElement.lang || undefined;
    let plurals;
    let numbers;
    try {
        plurals = new Intl.PluralRules(lang);
        numbers = new Intl.NumberFormat(lang);
    } catch {
        // An invalid language tag: the browser's own language rather than a module that stops here.
        plurals = new Intl.PluralRules();
        numbers = new Intl.NumberFormat();
    }
    const recount = () => {
        form.querySelectorAll('[data-tree-count]').forEach((count) => {
            const number = itemsOf(childList(count.closest('[data-id]'))).length;
            const text = counts[plurals.select(number)] ?? counts.other ?? Object.values(counts)[0];
            if (typeof text === 'string') {
                count.textContent = text.replace('#', numbers.format(number));
            }
        });
    };
    const setOpen = (item, open) => {
        item.classList.toggle('admin-tree-open', open);
        const toggle = item.querySelector(':scope > .admin-tree-row [data-tree-toggle]');
        if (toggle !== null) {
            toggle.ariaExpanded = String(open);
        }
    };
    // An item the dragged one could be dropped into: neither of them pinned, and its children's level within the depth
    // limit for the dragged item's height.
    const enterable = (item, dragged) => item !== dragged && !pinned(item) && !pinned(dragged)
        && childList(item) !== null && depthOf(childList(item), root) + height(dragged) <= maxDepth;
    // During a drag: the item under the pointer, and the timer that opens it if it stays collapsed there.
    let hovered = null;
    let hoverTimer = null;
    const hoverOver = (item) => {
        if (item === hovered) {
            return;
        }
        clearTimeout(hoverTimer);
        hovered = item;
        if (item !== null && !item.classList.contains('admin-tree-open') && itemsOf(childList(item)).length > 0) {
            hoverTimer = setTimeout(() => {
                setOpen(item, true);
                remember();
            }, OPEN_DELAY);
        }
    };
    // Sortable reports an item only while the pointer is over one (onMove), so leaving it for empty space (beside the
    // tree, below it) or another part of the page is noticed here: during a drag every pointer move (a native drag's
    // dragover, the fallback's pointer, mouse and touch moves) forgets the hovered item, and its timer, once the point
    // is neither on its row nor on the dragged item (resting on an item swaps the dragged item into the pointer's
    // place, next to the hovered one: that is still resting on it).
    let dragged = null;
    // True from a drop until its events are done: the click a drop may end with never toggles a row.
    let dropping = false;
    const leave = (event) => {
        if (hovered === null) {
            return;
        }
        const point = event.touches?.[0] ?? event;
        const under = document.elementFromPoint(point.clientX, point.clientY);
        if (!hovered.querySelector(':scope > .admin-tree-row').contains(under) && !dragged?.contains(under)) {
            hoverOver(null);
        }
    };
    const tracking = (item) => {
        dragged = item;
        const on = item !== null;
        ['dragover', 'pointermove', 'mousemove', 'touchmove'].forEach((type) => {
            if (on) {
                document.addEventListener(type, leave, { passive: true });
            } else {
                document.removeEventListener(type, leave);
            }
        });
    };

    // Saving: a timer between a drop and its save, a flag while a request runs, another drop meanwhile (saved again
    // once the request is done, with its new version), and an arrow button pressed meanwhile (submitted afterwards).
    let timer = null;
    let saving = false;
    let again = false;
    let submitter = null;
    // The toast of the last save: the next result replaces it, so the stack never piles up results of this form.
    let shown = null;
    const notify = (template, message) => {
        // When the previous result held the focus (its "Reload the page"), the new one takes it instead of the page.
        const hadFocus = shown?.contains(document.activeElement) ?? false;
        shown?.remove();
        const toast = template.content.firstElementChild.cloneNode(true);
        if (message !== null) {
            toast.querySelector('.admin-toast-text').textContent = message;
        }
        toast.querySelector('[data-toast-action]')?.addEventListener('click', () => {
            window.location.reload();
        });
        toasts.append(toast);
        shown = toast;
        if (hadFocus) {
            toast.querySelector('[data-toast-action], [data-toast-close]')?.focus();
        }
    };
    const save = async () => {
        timer = null;
        if (saving) {
            again = true;
            return;
        }
        saving = true;
        again = false;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            // A refused save answers JSON with the reason; anything else (an expired session's login page, a
            // missing CSRF token, a server error) is a plain failure.
            const json = (response.headers.get('Content-Type') || '').includes('application/json') ? await response.json() : {};
            if (response.ok && typeof json.version === 'string') {
                version.value = json.version;
                notify(savedToast, null);
            } else {
                // The template's own text is the generic failure.
                notify(failedToast, typeof json.error === 'string' ? json.error : null);
            }
        } catch {
            notify(failedToast, null);
        }
        saving = false;
        if (submitter !== null) {
            const button = submitter;
            submitter = null;
            form.requestSubmit(button);
        } else if (again) {
            save();
        }
    };
    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(save, SAVE_DELAY);
    };
    // An arrow button posts the whole tree, a pending drag included: no background save is needed any more. While
    // one runs, the button waits for it (and its new version).
    form.addEventListener('submit', (event) => {
        clearTimeout(timer);
        timer = null;
        again = false;
        if (saving) {
            event.preventDefault();
            submitter = event.submitter;
        }
        // An indented item lands in the item above it: keep that one open after the page reloads.
        const [id, move] = (event.submitter?.value ?? '').split(':');
        const above = move === 'indent' ? form.querySelector('[data-id="' + id + '"]')?.previousElementSibling : null;
        if (above) {
            setOpen(above, true);
            remember();
        }
    });
    // Leaving before a drag is saved asks first.
    window.addEventListener('beforeunload', (event) => {
        if (timer !== null || (saving && submitter === null)) {
            event.preventDefault();
        }
    });
    syncMoves();
    form.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const toggle = target?.closest('[data-tree-toggle]') ?? null;
        // "Add inside" opens its item first, so the new child shows when the screen comes back.
        const adder = target?.closest('[data-tree-opens]') ?? null;
        // A click on the free part of a row toggles its item too, unless it ends a text selection or a drag. Only
        // what is inside the row counts as its controls (the page's <main tabindex="-1"> holds every row).
        const hit = target?.closest('.admin-tree-row') ?? null;
        const row = hit !== null && !hit.contains(target.closest(INTERACTIVE)) && !dropping && String(window.getSelection() ?? '') === '' ? hit : null;
        const item = (toggle ?? adder ?? row)?.closest('[data-id]') ?? null;
        if (item === null || (row !== null && itemsOf(childList(item)).length === 0)) {
            return;
        }
        setOpen(item, adder !== null || !item.classList.contains('admin-tree-open'));
        remember();
    });
    form.querySelectorAll(LIST).forEach((list) => {
        Sortable.create(list, {
            group: 'tree-' + formIndex,
            handle: '[data-tree-handle]',
            draggable: '[data-id]',
            animation: 150,
            fallbackOnBody: true,
            swapThreshold: 0.65,
            emptyInsertThreshold: EMPTY_INSERT_THRESHOLD,
            onMove: (event) => {
                const allowed = depthOf(event.to, root) + height(event.dragged) <= maxDepth && (!pinned(event.dragged) || event.to === root);
                // Over an empty nested list Sortable reports the list itself: its item is the one hovered. Resting on
                // it opens it only when the dragged item could go into it.
                const over = event.related?.closest('[data-id]') ?? null;
                hoverOver(over !== null && enterable(over, event.dragged) ? over : null);
                return allowed;
            },
            onStart: (event) => {
                tracking(event.item);
            },
            // The dragged item changed lists: the toggles count it where it is now.
            onChange: () => {
                recount();
            },
            onEnd: (event) => {
                tracking(null);
                hoverOver(null);
                dropping = true;
                setTimeout(() => {
                    dropping = false;
                });
                // A drop into a collapsed item (its empty list, or one opened by hovering) leaves the item open.
                const parent = event.to.closest('[data-id]');
                if (parent !== null && !parent.classList.contains('admin-tree-open')) {
                    setOpen(parent, true);
                    remember();
                }
            },
            onSort: () => {
                update();
                recount();
                syncMoves();
                schedule();
            },
        });
    });
});
