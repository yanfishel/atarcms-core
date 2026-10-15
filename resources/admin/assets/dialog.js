// The page's modal dialog ([data-dialog]: the Sections screen, the media library's folders, the contact page's entry
// form): a link marked
// data-dialog-open loads its own page through htmx (which sends HX-Request, so the server answers with the dialog's
// fragment: its header with the title and the close button, its body and its footer) into [data-dialog-content] and
// opens the dialog. Forms in the fragment post through htmx: a refused one comes
// back into the dialog with its errors, a done one answers HX-Redirect and htmx loads the screen again. A
// [data-dialog-close] button, the close button and Escape close it; closing empties it and gives the focus back to the
// link. One request runs at a time: clicks on data-dialog-open links wait for it, and closing the dialog while it runs
// drops its answer (it is neither shown nor followed by a fallback; an HX-Redirect still reloads the screen, since the
// save is done). A done form may instead answer with markup for the screen itself (HX-Retarget, as the map column of
// the contacts page's form, so the form's unsaved text stays) and the "atar-dialog-done" event (HX-Trigger-After-Settle,
// detail {toast, focus}): the dialog closes, the success toast of the dialog's <template data-dialog-toast> is cloned
// into the page's stack with the toast's text, and the focus goes to the first dialog link (else the element) inside
// the element whose id is "focus", which replaced the link that opened the dialog. Without JavaScript (or htmx) the
// links open the full pages; a fragment that fails to load does the same, and a form that fails reloads the screen.
// The markup always comes from the server: this module only moves the focus, opens and closes the dialog and fills in
// a toast's text.
const dialog = document.querySelector('[data-dialog]');
const content = dialog?.querySelector('[data-dialog-content]') ?? null;
let opener = null; // the link that opened the dialog, focused again on close
let loading = null; // the link whose fragment is loading
let busy = false; // a request into the dialog's content is running
let dropped = false; // the dialog was closed while it ran: its answer is ignored

// The first invalid input of a refused form, else the element the fragment marks with autofocus.
const focusFirst = () => {
    (content.querySelector('.admin-field-invalid :is(input, select, textarea)') ?? content.querySelector('[autofocus]'))?.focus();
};

if (dialog !== null && content !== null && window.htmx) {
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (target?.closest('[data-dialog-close]') && dialog.contains(target)) {
            dialog.close();

            return;
        }
        const link = target?.closest('a[data-dialog-open]') ?? null;
        // A click with a modifier key (a new tab or window) opens the full page as usual.
        if (link === null || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        if (busy) {
            return;
        }
        if (!dialog.open) {
            opener = link;
        }
        loading = link;
        window.htmx.ajax('GET', link.pathname + link.search, { target: content, swap: 'innerHTML' });
    });

    // The requests into the dialog's content: a link's fragment or a dialog form's post. Its end is read from the request
    // itself: a form emptied out of a closed dialog no longer sends htmx's events up to the document.
    document.addEventListener('htmx:beforeRequest', (event) => {
        if (event.detail.target === content) {
            busy = true;
            dropped = false;
            event.detail.xhr.addEventListener('loadend', () => {
                busy = false;
            });
        }
    });
    // An answer that arrives after the dialog was closed is not swapped in, so the dialog stays closed.
    document.addEventListener('htmx:beforeSwap', (event) => {
        if (event.detail.target === content && dropped) {
            event.detail.shouldSwap = false;
        }
    });

    // A fragment arrived (a link's, or a refused form's): show it.
    document.addEventListener('htmx:afterSettle', (event) => {
        if (event.detail.target !== content) {
            return;
        }
        loading = null;
        if (!dialog.open) {
            dialog.showModal();
        }
        focusFirst();
    });

    // A link's fragment could not be loaded: open its full page instead. A form of the dialog that failed (an expired
    // session or CSRF token, a server error) reloads the screen, which shows what happened. Nothing happens for a
    // request whose dialog was closed meanwhile.
    const fallBack = (event) => {
        if (event.detail.target !== content || dropped) {
            return;
        }
        if (loading !== null) {
            const link = loading;
            loading = null;
            window.location.assign(link.href);
        } else {
            window.location.reload();
        }
    };
    document.addEventListener('htmx:responseError', fallBack);
    document.addEventListener('htmx:sendError', fallBack);

    // A done form whose answer replaced part of the screen: close the dialog, show the toast, focus the new element.
    document.addEventListener('atar-dialog-done', (event) => {
        const detail = event.detail ?? {};
        const replaced = typeof detail.focus === 'string' ? document.getElementById(detail.focus) : null;
        const target = replaced?.querySelector('a[data-dialog-open]') ?? replaced;
        const template = dialog.querySelector('template[data-dialog-toast]');
        const toasts = document.querySelector('[data-toasts]');
        if (typeof detail.toast === 'string' && template !== null && toasts !== null) {
            const toast = template.content.firstElementChild.cloneNode(true);
            toast.querySelector('.admin-toast-text').textContent = detail.toast;
            toasts.append(toast);
        }
        if (dialog.open) {
            // The closing gives the focus to the opener: the opener's successor now.
            opener = target;
            dialog.close();
        } else if (document.activeElement === null || document.activeElement === document.body) {
            target?.focus();
        }
    });

    dialog.addEventListener('close', () => {
        dropped = busy;
        loading = null;
        content.replaceChildren();
        opener?.focus();
        opener = null;
    });
}
