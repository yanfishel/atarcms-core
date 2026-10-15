// Machine translation in the browser (Plan 38) with the browser's on-device Translator API (Chrome: the Translator
// global; no key, nothing leaves the page, the model is downloaded by the browser itself). Without the API (Firefox,
// Safari, mobile browsers) or without JavaScript nothing here shows and the admin offers only "Copy for translation".
// - Offers: every [data-machine-offer] button (the second submit button of a "Copy for translation" form, rendered
//   hidden) is shown when the pair data-machine-from → data-machine-to is available, downloadable or downloading.
// - The copy's form: the panel [data-machine] (partials/machine-translation.php, rendered hidden) is shown the same
//   way. Its button creates the translator (the user gesture a download needs; the progress bar follows the download,
//   then the texts); with data-machine-start (the editor chose machine translation) and a model already on the device
//   it starts at once. It translates the inputs named in data-machine-fields and the title, subtitle and button text
//   of the slides fields in data-machine-slides; rich text is translated by its editor (editor.js answers the
//   "rich-text-translate" event of its textarea: block by block with the inline markup, which must come back
//   unchanged, else text node by text node). The plain controls are read-only meanwhile. All or nothing: the results
//   are applied only when every text is translated, the editors first (one that fails undoes the others), so a
//   failure leaves the copy as it was (an error toast). Applying fires "machine-translated" on the form first
//   (autosave.js stops: nothing is saved before the editor's own Save), then "input" and "change" on each changed
//   control; data-machine-slug empties the address input, so a draft's first save makes it from the translated title
//   (EntryService). A term or section form then asks before leaving until it is saved. "start" leaves the address at
//   once and "machine" after a translation (history.replaceState), so Back or a reload never translates again. The
//   toasts are clones of the panel's <template>s. DOM is changed through properties and textContent only (CSP, no
//   markup injection, no requests).

const SHOWN = new Set(['available', 'downloadable', 'downloading']);
const SLIDE_TEXTS = ['title', 'subtitle', 'button'];

// The site's locale codes are BCP 47 tags already ("he", not the old "iw"); only the case and "_" are normalised.
export function bcp47(code) {
    return String(code).trim().replace(/_/g, '-').toLowerCase();
}

// A text worth translating has a letter and is not an address, an e-mail address or a domain; numbers, dates, phones
// and codes have no letter or no space-separated words to translate.
export function translatable(text) {
    const value = String(text).trim();
    if (value === '' || !/\p{L}/u.test(value)) {
        return false;
    }
    if (/^[a-z][a-z0-9+.-]*:\/\/\S*$/i.test(value) || /^(mailto|tel):\S+$/i.test(value) || /^www\.\S+$/i.test(value)) {
        return false;
    }

    return !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) && !/^[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i.test(value);
}

// Translates one text, keeping its leading and trailing white space; a text not worth translating comes back as it is.
export async function translateText(translator, text) {
    const [, lead, core, trail] = /^(\s*)([\s\S]*?)(\s*)$/.exec(String(text));
    if (!translatable(core)) {
        return String(text);
    }

    return lead + (await translator.translate(core)) + trail;
}

const pairs = new Map();

// Translator.availability() of a pair, once per page; anything that throws (an unknown language) is "unavailable".
function availability(from, to) {
    const key = bcp47(from) + '>' + bcp47(to);
    if (!pairs.has(key)) {
        pairs.set(key, Promise.resolve()
            .then(() => globalThis.Translator.availability({ sourceLanguage: bcp47(from), targetLanguage: bcp47(to) }))
            .catch(() => 'unavailable'));
    }

    return pairs.get(key);
}

const supported = () => typeof globalThis.Translator === 'function' || typeof globalThis.Translator === 'object';

function toast(template) {
    const stack = document.querySelector('[data-toasts]');
    if (stack === null || template === null) {
        return;
    }
    stack.append(template.content.firstElementChild.cloneNode(true));
}

const names = (json) => {
    try {
        const list = JSON.parse(json || '[]');

        return Array.isArray(list) ? list.filter((name) => typeof name === 'string') : [];
    } catch {
        return [];
    }
};

// The plain controls to translate: the named inputs and textareas (rich text goes through its editor) and the
// slides' texts.
function plainControls(form, panel) {
    const controls = [];
    for (const name of names(panel.dataset.machineFields)) {
        const control = form.elements.namedItem(name);
        if ((control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement) && !control.readOnly && !control.matches('[data-rich-text]')) {
            controls.push(control);
        }
    }
    for (const field of names(panel.dataset.machineSlides)) {
        const prefix = 'fields[' + field + '][';
        for (const control of form.querySelectorAll('input[data-slides-input]')) {
            if (control.name.startsWith(prefix) && SLIDE_TEXTS.includes(control.dataset.slidesInput)) {
                controls.push(control);
            }
        }
    }

    return controls;
}

function richTextAreas(form, panel) {
    return names(panel.dataset.machineFields)
        .map((name) => form.elements.namedItem(name))
        .filter((control) => control instanceof HTMLTextAreaElement && control.matches('[data-rich-text]'));
}

// The query of the copy's form: "start" (the editor chose machine translation) counts once, so Back or a reload never
// starts it again; after a translation "machine" goes too, so the reloaded form is the saved one without the panel.
function dropQuery(...keys) {
    const url = new URL(window.location.href);
    if (keys.some((key) => url.searchParams.has(key))) {
        keys.forEach((key) => url.searchParams.delete(key));
        history.replaceState(history.state, '', url);
    }
}

function setUp(panel) {
    const form = panel.closest('form');
    const run = panel.querySelector('[data-machine-run]');
    const progress = panel.querySelector('[data-machine-progress]');
    if (form === null || run === null || progress === null) {
        return;
    }
    const from = bcp47(panel.dataset.machineFrom);
    const to = bcp47(panel.dataset.machineTo);
    const start = panel.hasAttribute('data-machine-start');
    dropQuery('start');
    let busy = false;
    let unsaved = false;
    // The entry form's leave warning is autosave.js's; the term and section form gets its own while translated texts
    // wait for Save.
    if (!form.matches('[data-entry-form]')) {
        form.addEventListener('submit', () => {
            unsaved = false;
        });
        window.addEventListener('beforeunload', (event) => {
            if (unsaved) {
                event.preventDefault();
            }
        });
    }

    const translate = async () => {
        if (busy) {
            return;
        }
        busy = true;
        run.disabled = true;
        progress.value = 0;
        progress.hidden = false;
        let translator = null;
        const jobs = [];
        // Nothing typed meanwhile is overwritten: the plain controls are read-only until the end.
        const plain = plainControls(form, panel);
        const locked = new Map(plain.map((control) => [control, control.readOnly]));
        for (const control of plain) {
            control.readOnly = true;
        }
        try {
            translator = await globalThis.Translator.create({
                sourceLanguage: from,
                targetLanguage: to,
                monitor(monitor) {
                    monitor.addEventListener('downloadprogress', (event) => {
                        progress.value = event.loaded;
                    });
                },
            });
            const editors = richTextAreas(form, panel);
            const total = plain.length + editors.length;
            let done = 0;
            const step = () => {
                done += 1;
                progress.value = total === 0 ? 1 : done / total;
            };
            progress.value = 0;
            const values = [];
            for (const control of plain) {
                values.push(await translateText(translator, control.value));
                step();
            }
            // Each editor translates its blocks, read-only meanwhile, and hands back how to apply, undo or drop them
            // (editor.js).
            for (const textarea of editors) {
                const detail = { translate: (text) => translateText(translator, text), translatable, pending: [] };
                textarea.dispatchEvent(new CustomEvent('rich-text-translate', { detail }));
                const results = await Promise.allSettled(detail.pending);
                jobs.push(...results.filter((result) => result.status === 'fulfilled').map((result) => result.value));
                const failure = results.find((result) => result.status === 'rejected');
                if (failure !== undefined) {
                    throw failure.reason;
                }
                step();
            }

            // The editors first: one that fails undoes those applied before it, and nothing else has changed yet.
            form.dispatchEvent(new CustomEvent('machine-translated', { bubbles: true }));
            const applied = [];
            try {
                for (const job of jobs) {
                    job.apply();
                    applied.push(job);
                }
            } catch (error) {
                for (const job of applied.reverse()) {
                    job.revert();
                }
                throw error;
            }
            jobs.length = 0;
            plain.forEach((control, index) => {
                if (control.value !== values[index]) {
                    control.value = values[index];
                    control.dispatchEvent(new Event('input', { bubbles: true }));
                    control.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            const slug = panel.hasAttribute('data-machine-slug') ? form.elements.namedItem('slug') : null;
            if (slug instanceof HTMLInputElement && !slug.readOnly && slug.value !== '') {
                slug.value = '';
                slug.dispatchEvent(new Event('input', { bubbles: true }));
                slug.dispatchEvent(new Event('change', { bubbles: true }));
            }
            unsaved = true;
            dropQuery('machine', 'start');
            run.hidden = true;
            progress.hidden = true;
            toast(panel.querySelector('template[data-machine-done]'));
        } catch (error) {
            console.error('Machine translation failed', error);
            progress.hidden = true;
            run.disabled = false;
            toast(panel.querySelector('template[data-machine-failed]'));
        } finally {
            // Editors not applied are unlocked as they were; the plain controls get their own state back.
            for (const job of jobs.splice(0)) {
                job.release();
            }
            for (const control of plain) {
                control.readOnly = locked.get(control);
            }
            translator?.destroy?.();
            busy = false;
        }
    };

    availability(from, to).then((state) => {
        if (!SHOWN.has(state)) {
            return;
        }
        panel.hidden = false;
        run.addEventListener('click', translate);
        if (state === 'available' && start) {
            translate();
        }
    });
}

// The offers' machine buttons, also those a dialog loads later (the term dialog's Translations line, through htmx).
const offered = new WeakSet();

function showOffers() {
    for (const button of document.querySelectorAll('[data-machine-offer]')) {
        if (offered.has(button)) {
            continue;
        }
        offered.add(button);
        availability(button.dataset.machineFrom, button.dataset.machineTo).then((state) => {
            button.hidden = !SHOWN.has(state);
        });
    }
}

if (supported()) {
    showOffers();
    document.addEventListener('htmx:afterSettle', () => showOffers());
    // After every module of the page ran (editor.js listens for "rich-text-translate").
    const panels = () => document.querySelectorAll('[data-machine]').forEach(setUp);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', panels);
    } else {
        panels();
    }
}
