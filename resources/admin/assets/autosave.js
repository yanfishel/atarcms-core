// Autosave of drafts and the leave warning of the entry form ([data-entry-form]). The form works without JavaScript;
// this module only adds:
// - Autosave, when the server offers it (data-autosave: a new entry or a stored draft, never a published or scheduled
//   entry or a plain single) and "Draft" is the chosen status: SAVE_DELAY after the last change of the form, and when
//   the page is hidden or left (best effort: a "keepalive" request, which browsers limit in size). One request at a
//   time, to data-autosave only, with the whole form (its CSRF field and version included) and "Accept:
//   application/json". The answer's version goes into the "version" input; the first autosave of a new entry creates it,
//   and the form then edits that entry (its action, data-autosave, the Preview button's formaction and the address bar
//   change to the answer's, the Preview's tab name too). An invalid form (422) is skipped quietly and tried again
//   SAVE_DELAY later: the field errors show when the user saves. A refusal stops autosave for this page view with an
//   error toast: the server's reason (409: someone else saved the entry, or it is no longer a draft), an expired page
//   (419, or a redirect to the login page) or lost rights (403, 404); a network or server error (or no answer within
//   REQUEST_TIMEOUT) shows the toast and tries again SAVE_DELAY later. A success removes the error toast and has none of
//   its own (a user decision): the status text of
//   the action bar says "Saving…", then "Saved just now" / "Saved N minutes ago", refreshed every TICK, in the plural
//   forms the server rendered (data-saved-minutes, data-saved-hours: Intl.PluralRules picks one, "#" is the number);
//   a hidden status element announces each save.
// - Editors (editor.js) sync on the cancelable "entry-form-sync" event before each autosave (cancelled: an upload is
//   running, the autosave waits) and report their edits as "input"; media widgets (media.js) report theirs as "change".
// - A manual save during an autosave waits for it (its new version, at most REQUEST_TIMEOUT), then submits again with
//   the same button.
// - Leaving with unsaved changes asks first (beforeunload) when they are not autosaved: a published or scheduled
//   entry, another status chosen, autosave stopped, or the last autosave failed (an invalid form, a network error).
// - A machine translation (translate.js, Plan 38: "machine-translated" on the form, fired before its texts change)
//   stops autosave for this page view without a toast: the translated texts are saved only by the editor's own Save,
//   and leaving before that asks first.
// - Back to a page the browser kept (back/forward cache), the form reloads only when its version is known to be old:
//   a save on leaving succeeded, or the entry was saved elsewhere (409); otherwise the restored form stays as it was.

const SAVE_DELAY = 30000;
const TICK = 30000;
const REQUEST_TIMEOUT = 15000;

const form = document.querySelector('form[data-entry-form]');
if (form !== null) {
    setUp(form);
}

function setUp(form) {
    const status = form.querySelector('[data-autosave-status]');
    const text = status?.querySelector('[data-autosave-text]') ?? null;
    const announce = status?.querySelector('[data-autosave-announce]') ?? null;
    const failed = form.querySelector('template[data-autosave-failed]');
    const toasts = document.querySelector('[data-toasts]');
    const version = form.elements.namedItem('version');
    const preview = form.querySelector('[data-preview]');
    const initialText = text?.textContent ?? '';

    let url = form.dataset.autosave ?? null;
    let stopped = false;
    let dirty = false;
    let timer = null;
    let saving = false;
    let again = false;
    let waiting = false;
    let submitter = null;
    let leaving = false;
    let savedAt = null;
    let ticker = null;
    let shown = null;
    // The last autosave did not save the changes (they are only on the page), a save on leaving succeeded, the
    // entry's version is known to be old.
    let lastFailed = false;
    let leaveSaved = false;
    let stale = false;

    const parse = (json) => {
        try {
            return JSON.parse(json || '{}');
        } catch {
            return {};
        }
    };
    const minutes = parse(status?.dataset.savedMinutes);
    const hours = parse(status?.dataset.savedHours);
    let rules = null;
    try {
        rules = new Intl.PluralRules(status?.dataset.locale || undefined);
    } catch {
        rules = new Intl.PluralRules();
    }
    const plural = (forms, n) => (forms[rules.select(n)] ?? forms.other ?? '').replace('#', String(n));

    const draftChosen = () => {
        const checked = form.querySelector('[data-status-choice]:checked');

        return checked === null || checked.value === 'draft';
    };
    const active = () => url !== null && !stopped && !leaving && draftChosen();

    const showText = (value) => {
        if (text === null) {
            return;
        }
        // Once the script writes the status, the hidden announcement speaks for it: the ticking text stays silent.
        text.ariaHidden = 'true';
        text.textContent = value;
    };
    const render = () => {
        if (savedAt === null) {
            if (text !== null) {
                text.ariaHidden = null;
                text.textContent = initialText;
            }
            return;
        }
        const elapsed = Math.floor((Date.now() - savedAt) / 60000);
        showText(elapsed < 1
            ? status.dataset.savedNow
            : (elapsed < 60 ? plural(minutes, elapsed) : plural(hours, Math.floor(elapsed / 60))));
    };

    // The error toast of the last failure: a new one replaces it, so the stack never piles up autosave errors.
    const notify = (message) => {
        if (failed === null || toasts === null) {
            return;
        }
        shown?.remove();
        const toast = failed.content.firstElementChild.cloneNode(true);
        if (typeof message === 'string' && message !== '') {
            toast.querySelector('.admin-toast-text').textContent = message;
        }
        toasts.append(toast);
        shown = toast;
    };
    const stop = (message) => {
        stopped = true;
        clearTimeout(timer);
        timer = null;
        notify(message);
    };

    const schedule = () => {
        clearTimeout(timer);
        timer = active() ? setTimeout(() => save(false), SAVE_DELAY) : null;
    };

    const saved = (json, keepalive) => {
        version.value = json.version;
        lastFailed = false;
        leaveSaved = keepalive;
        shown?.remove();
        shown = null;
        if (typeof json.autosave === 'string' && json.autosave !== url) {
            // A new entry was created: from now on the form edits it.
            url = json.autosave;
            form.dataset.autosave = json.autosave;
            if (typeof json.action === 'string') {
                form.action = json.action;
            }
            if (preview !== null && typeof json.preview === 'string') {
                preview.formAction = json.preview;
                if (typeof json.previewTarget === 'string') {
                    preview.formTarget = json.previewTarget;
                }
            }
            if (typeof json.edit === 'string') {
                window.history.replaceState(window.history.state, '', json.edit);
            }
        }
        savedAt = Date.now();
        render();
        if (announce !== null) {
            announce.textContent = status.dataset.savedNow;
        }
        if (ticker === null) {
            ticker = setInterval(render, TICK);
        }
    };

    const save = async (keepalive) => {
        timer = null;
        if (!active() || !dirty) {
            return;
        }
        if (saving) {
            again = true;
            return;
        }
        if (!form.dispatchEvent(new CustomEvent('entry-form-sync', { cancelable: true }))) {
            schedule();
            return;
        }
        saving = true;
        again = false;
        dirty = false;
        if (!keepalive) {
            showText(status?.dataset.saving ?? '');
        }
        // Anything but a success leaves the changes unsaved: they count as changed again.
        const unsaved = () => {
            dirty = true;
            lastFailed = true;
            render();
        };
        try {
            const response = await fetch(url, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                keepalive,
                signal: AbortSignal.timeout(REQUEST_TIMEOUT),
            });
            // Refusals answer JSON; an expired page (419) or lost rights answer an error page, an expired session a
            // redirect to the login page.
            const json = !response.redirected && (response.headers.get('Content-Type') || '').includes('application/json') ? await response.json() : {};
            if (response.ok && !response.redirected && typeof json.version === 'string') {
                saved(json, keepalive);
            } else if (response.status === 422 && json.invalid === true) {
                // Not savable yet (an empty required field…): quietly try again later.
                unsaved();
                schedule();
            } else if (response.status === 409 && typeof json.error === 'string') {
                unsaved();
                stale = true;
                stop(json.error);
            } else if (response.status === 419 || response.redirected) {
                unsaved();
                stop(failed?.dataset.expired);
            } else if (response.status === 403 || response.status === 404) {
                unsaved();
                stop(failed?.dataset.forbidden);
            } else {
                unsaved();
                notify(null);
                schedule();
            }
        } catch {
            unsaved();
            // A keepalive request too large for the browser fails at once: a hidden page tries a normal one.
            if (keepalive && !leaving) {
                saving = false;
                save(false);
                return;
            }
            if (!keepalive) {
                notify(null);
                schedule();
            }
        }
        saving = false;
        if (waiting) {
            waiting = false;
            const button = submitter;
            submitter = null;
            form.requestSubmit(button);
        } else if (again) {
            save(keepalive);
        }
    };

    const changed = () => {
        dirty = true;
        schedule();
    };
    // Choosing a status other than "Draft" is a change too: schedule() then sees autosave inactive and stops the timer;
    // back to "Draft", it starts again.
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    form.addEventListener('machine-translated', () => {
        stopped = true;
        clearTimeout(timer);
        timer = null;
    });

    form.addEventListener('submit', (event) => {
        // Preview opens in another tab: the page stays, and so does autosave.
        if (event.defaultPrevented || event.submitter?.matches('[data-preview]')) {
            return;
        }
        clearTimeout(timer);
        timer = null;
        again = false;
        if (saving) {
            // Wait for the running autosave: the manual save must send its new version.
            event.preventDefault();
            waiting = true;
            submitter = event.submitter ?? null;
            return;
        }
        leaving = true;
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            clearTimeout(timer);
            save(true);
        }
    });
    window.addEventListener('pagehide', () => {
        clearTimeout(timer);
        save(true);
    });
    // Back to a page the browser kept (back/forward cache): reload only when its version is known to be old (a save
    // on leaving succeeded and nothing changed since, or the entry was saved elsewhere); else keep the restored form.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted && url !== null && ((leaveSaved && !dirty) || stale)) {
            window.location.reload();
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (dirty && !leaving && (lastFailed || !active())) {
            event.preventDefault();
        }
    });
}
