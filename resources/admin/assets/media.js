// The media library's drop zones and bulk actions, the media item page's "Copy link", and the picker dialog with the
// image, gallery and file widgets of the entry form. The rich-text editor (editor.js) opens the same dialog with a
// "media-pick" event and gets the chosen image back as a "media-picked" event with its address and alt text.
// Without JavaScript the upload and bulk forms post normally and every widget is a text input of media ids, which
// the server validates either way. DOM is built with createElement/textContent only (CSP, no markup injection).
import Sortable from './vendor/sortable-1.15.7.esm.js';

const dialog = document.querySelector('[data-media-dialog]');
const footer = dialog?.querySelector('[data-picker-footer]') ?? null;
let current = null; // the widget the dialog is picking for
let insert = false; // the dialog is picking an image for the rich-text editor
let multiple = false; // a gallery: several files, added in the order they were picked
// The selection, kept across folders, pages, searches and filters: id => the file's name, preview, address and alt
// text, in the order the files were picked (a Map keeps insertion order).
const selection = new Map();
let taken = new Set(); // the ids a gallery already holds: marked and not selectable
let unchanged = null; // a single widget's current file: choosing it again changes nothing

function openPicker(kind, locale) {
    const url = new URL(dialog.dataset.pickerUrl, window.location.href);
    url.searchParams.set('kind', kind);
    if (locale) {
        url.searchParams.set('locale', locale);
    }
    showSelection();
    window.htmx.ajax('GET', url.pathname + url.search, { target: dialog.querySelector('[data-media-dialog-body]'), swap: 'innerHTML' });
    dialog.showModal();
}

const idsInput = (widget) => widget.querySelector('[data-media-ids]');
const chosenList = (widget) => widget.querySelector('[data-media-chosen]');
const isMultiple = (widget) => widget.dataset.multiple === '1';

// The entry form's media tab shows how many files its widgets hold (FormTab::$count): the server rendered the counter,
// hidden at 0; a widget on another tab (or outside the entry form) has none.
function countTab(widget) {
    const panel = widget.closest('[role="tabpanel"]');
    const tab = panel === null ? null : document.getElementById(panel.getAttribute('aria-labelledby'));
    const counter = tab?.querySelector('[data-tab-counter]') ?? null;
    if (counter === null) {
        return;
    }
    const count = panel.querySelectorAll('[data-media-chosen] > [data-id]').length;
    counter.hidden = count === 0;
    for (const number of counter.querySelectorAll('[data-tab-count]')) {
        number.textContent = String(count);
    }
}

// A script's change to a hidden input fires no event: "change" tells the entry form (autosave.js) that it has changed.
function sync(widget) {
    const input = idsInput(widget);
    input.value = [...chosenList(widget).children].map((item) => item.dataset.id).join(',');
    input.dispatchEvent(new Event('change', { bubbles: true }));
    countTab(widget);
}

function chosenItem(widget, id, file) {
    const item = document.createElement('li');
    item.dataset.id = id;
    item.dataset.name = file.name;
    if (file.url) {
        // The slide dialog (slides.js) reads the chosen video's address from here.
        item.dataset.url = file.url;
    }
    if (widget.dataset.kind === 'video' && file.url) {
        // A video's card is its player: no thumbnail and no name.
        const video = document.createElement('video');
        video.className = 'admin-media-video';
        video.controls = true;
        video.preload = 'metadata';
        video.src = file.url;
        item.append(video);
    } else {
        if (file.thumb) {
            const image = document.createElement('img');
            image.className = 'admin-media-thumb';
            image.src = file.thumb;
            image.alt = '';
            item.append(image);
        }
        const name = document.createElement('span');
        name.className = 'admin-media-name';
        name.textContent = file.name;
        name.title = file.name;
        item.append(name);
    }
    // The icon-only remove button, cloned from the widget's server-rendered copy (its icon links the sprite).
    item.append(widget.querySelector('[data-media-remove-template]').content.firstElementChild.cloneNode(true));

    return item;
}

for (const widget of document.querySelectorAll('[data-media-field]')) {
    // The ids input stays visible for users who cannot upload (no Choose button): they may still type ids.
    if (widget.querySelector('[data-media-choose]') !== null) {
        widget.classList.add('admin-media-enabled');
    }
    if (isMultiple(widget)) {
        Sortable.create(chosenList(widget), { animation: 150, onEnd: () => sync(widget) });
    }
}

const describe = (tile) => ({ name: tile.dataset.name, thumb: tile.dataset.thumb, url: tile.dataset.url, alt: tile.dataset.alt || '' });

// The footer: "Selected: photo.jpg" (one file) or "Selected: 3" (a gallery), Choose or "Add (3)", disabled while
// nothing is selected. The texts come from the server with "#" for the name or the count.
function showSelection() {
    if (footer === null) {
        return;
    }
    const status = footer.querySelector('[data-picker-status]');
    if (selection.size === 0) {
        status.textContent = footer.dataset.none;
    } else {
        status.textContent = footer.dataset.selected.replace('#', () => (multiple ? String(selection.size) : selection.values().next().value.name));
    }
    const button = footer.querySelector('[data-picker-choose]');
    button.textContent = multiple ? footer.dataset.add.replace('#', () => String(selection.size)) : footer.dataset.choose;
    button.disabled = selection.size === 0;
    footer.querySelector('[data-picker-clear]').hidden = !multiple;
    showSelectAll();
}

// After every load of the picker's content: files just uploaded join the selection (one file: the first of them),
// the tiles show the selection, and a gallery's files are "Already in the gallery" and cannot be picked again.
function markTiles() {
    if (dialog === null) {
        return;
    }
    const uploaded = [...dialog.querySelectorAll('[data-media-pick][data-uploaded]')];
    for (const tile of uploaded) {
        delete tile.dataset.uploaded;
    }
    const fresh = uploaded.filter((tile) => !taken.has(tile.dataset.mediaPick));
    if (fresh.length > 0 && !multiple) {
        selection.clear();
        selection.set(fresh[0].dataset.mediaPick, describe(fresh[0]));
    } else {
        for (const tile of fresh) {
            selection.set(tile.dataset.mediaPick, describe(tile));
        }
    }
    for (const tile of dialog.querySelectorAll('[data-media-pick]')) {
        const id = tile.dataset.mediaPick;
        tile.disabled = taken.has(id);
        tile.checked = selection.has(id);
        const info = tile.closest('[data-media-item]')?.querySelector('[data-pick-info]') ?? null;
        if (taken.has(id) && info !== null && info.querySelector('[data-media-already]') === null) {
            const label = document.createElement('span');
            label.className = 'admin-media-already';
            label.dataset.mediaAlready = '';
            label.textContent = footer.dataset.already;
            info.append(label);
        }
    }
    showSelection();
}

function toggle(tile) {
    const id = tile.dataset.mediaPick;
    if (tile.disabled) {
        return;
    }
    if (selection.has(id)) {
        selection.delete(id);
    } else {
        if (!multiple) {
            selection.clear();
        }
        selection.set(id, describe(tile));
    }
    markTiles();
}

// Choose / Add: the editor gets its image, a widget the selection in the order it was picked (a single widget's
// file is replaced, unless it is the same one; a gallery keeps its files and the new ones are appended).
function choose() {
    if (selection.size === 0) {
        return;
    }
    const picked = [...selection];
    if (insert) {
        const [, file] = picked[0];
        insert = false;
        document.dispatchEvent(new CustomEvent('media-picked', { detail: { url: file.url, alt: file.alt } }));
    } else if (current !== null && (multiple || picked[0][0] !== unchanged)) {
        const list = chosenList(current);
        if (!multiple) {
            list.replaceChildren();
        }
        for (const [id, file] of picked) {
            if (![...list.children].some((item) => item.dataset.id === id)) {
                list.append(chosenItem(current, id, file));
            }
        }
        sync(current);
    }
    dialog.close();
}

// One file picked at once (a double click, Enter): it becomes the selection and is chosen.
function chooseOnly(tile) {
    selection.clear();
    selection.set(tile.dataset.mediaPick, describe(tile));
    choose();
}

// Opening the picker for a widget: a gallery marks the files it holds, a single widget starts with its file selected.
function pickFor(widget) {
    current = widget;
    insert = false;
    multiple = isMultiple(widget);
    selection.clear();
    const items = [...chosenList(widget).children];
    taken = new Set(multiple ? items.map((item) => item.dataset.id) : []);
    unchanged = null;
    if (!multiple && items.length > 0) {
        unchanged = items[0].dataset.id;
        selection.set(unchanged, {
            name: items[0].querySelector('.admin-media-name')?.textContent ?? '',
            thumb: items[0].querySelector('img')?.src ?? '',
            url: '',
            alt: '',
        });
    }
    openPicker(widget.dataset.kind, '');
}

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (target === null) {
        return;
    }
    const remove = target.closest('[data-media-remove]');
    if (remove !== null) {
        const widget = remove.closest('[data-media-field]');
        remove.closest('[data-id]').remove();
        sync(widget);

        return;
    }
    const chooser = target.closest('[data-media-choose]');
    if (chooser !== null && dialog !== null && window.htmx) {
        pickFor(chooser.closest('[data-media-field]'));

        return;
    }
    // A click anywhere in a list row (the library's or the picker's) checks its file, as its checkbox would.
    const row = target.closest('[data-media-row]');
    if (row !== null && target.closest('a, button, input, label') === null) {
        row.querySelector('[data-media-pick], input[form="media-bulk"]')?.click();

        return;
    }
    if (dialog === null || !dialog.contains(target)) {
        return;
    }
    if (target.closest('[data-picker-clear]') !== null) {
        selection.clear();
        markTiles();
    } else if (target.closest('[data-picker-cancel]') !== null) {
        dialog.close();
    } else if (target.closest('[data-picker-choose]') !== null) {
        choose();
    }
});

// The files are checkboxes, as in the library: a click on the card, the row or the box (Space) toggles one.
dialog?.addEventListener('change', (event) => {
    const tile = event.target instanceof HTMLInputElement && event.target.matches('[data-media-pick]') ? event.target : null;
    if (tile !== null) {
        toggle(tile);
    }
});

// One file: a double click or Enter on a file chooses it at once; in a gallery Enter toggles, and "Add" finishes.
const tileOf = (event) => (event.target instanceof Element ? event.target.closest('[data-media-item]')?.querySelector('[data-media-pick]') ?? null : null);

dialog?.addEventListener('dblclick', (event) => {
    const tile = tileOf(event);
    if (tile !== null && !multiple && !tile.disabled) {
        chooseOnly(tile);
    }
});

dialog?.addEventListener('keydown', (event) => {
    const tile = tileOf(event);
    if (tile === null || event.key !== 'Enter' || tile.disabled) {
        return;
    }
    event.preventDefault();
    if (multiple) {
        toggle(tile);
    } else {
        chooseOnly(tile);
    }
});

// The rich-text editor asks for one image, with the alt texts of its content language. A dialog closed without a
// choice sends nothing back.
document.addEventListener('media-pick', (event) => {
    if (dialog === null || !window.htmx) {
        return;
    }
    current = null;
    insert = true;
    multiple = false;
    taken = new Set();
    unchanged = null;
    selection.clear();
    openPicker('image', event.detail?.locale || '');
});

dialog?.addEventListener('close', () => {
    // The event comes a task later: a picker opened again in the meantime (the editor's button right after Choose)
    // keeps its mode and selection.
    if (!dialog.open) {
        insert = false;
        selection.clear();
    }
});

document.addEventListener('htmx:afterSettle', () => {
    if (dialog?.open) {
        markTiles();
    }
});

// The library's bulk bar floats at the bottom while files are checked: "3 selected" (without JavaScript it is always
// there and the server refuses an empty selection). The results, bar included, are swapped by the toolbar and by
// uploads, so the bar is looked up each time.
function countChecked() {
    const bulk = document.querySelector('[data-media-bulk]');
    if (bulk === null) {
        return;
    }
    const count = document.querySelectorAll('input[form="media-bulk"]:checked').length;
    bulk.querySelector('[data-media-bulk-count]').textContent = bulk.dataset.selected.replace('#', () => String(count));
    bulk.classList.toggle('admin-media-bulk-active', count > 0);
    for (const button of bulk.querySelectorAll('[data-media-bulk-action]')) {
        button.disabled = count === 0;
    }
    showSelectAll();
}

// "Select all" / "Clear selection" beside the count (rendered hidden: JavaScript only): the library checks or unchecks
// the files shown; a gallery's picker adds the shown files in page order (not those already in the gallery) or clears
// the whole selection, as the footer's Clear does. A single file's picker has neither.
const selectableIn = (results) => (results.closest('dialog') !== null
    ? [...results.querySelectorAll('[data-media-pick]')].filter((box) => !box.disabled)
    : [...results.querySelectorAll('input[form="media-bulk"]')]);

function showSelectAll() {
    for (const results of document.querySelectorAll('[data-media-results]')) {
        const all = results.querySelector('[data-select-all]');
        const none = results.querySelector('[data-select-none]');
        if (all === null || none === null) {
            continue;
        }
        const inPicker = results.closest('dialog') !== null;
        const boxes = selectableIn(results);
        all.hidden = inPicker && !multiple;
        none.hidden = all.hidden;
        all.disabled = boxes.every((box) => box.checked);
        none.disabled = inPicker ? selection.size === 0 : !boxes.some((box) => box.checked);
    }
}

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-select-all], [data-select-none]') : null;
    const results = button?.closest('[data-media-results]') ?? null;
    if (button === null || results === null) {
        return;
    }
    const select = button.matches('[data-select-all]');
    if (results.closest('dialog') !== null) {
        if (select) {
            for (const box of selectableIn(results)) {
                selection.set(box.dataset.mediaPick, describe(box));
            }
        } else {
            selection.clear();
        }
        markTiles();

        return;
    }
    for (const box of selectableIn(results)) {
        box.checked = select;
    }
    countChecked();
});

// The toolbar's dropdowns (<details data-media-dropdown> with radio options of the filter form): one open at a time,
// a click outside or Escape closes it. Choosing an option shows it in the summary (cloned from the option, the icon,
// the text or both as data-dropdown-show says) and submits the form.
const dropdowns = () => [...document.querySelectorAll('[data-media-dropdown]')];

document.addEventListener('toggle', (event) => {
    const opened = event.target;
    if (opened instanceof HTMLDetailsElement && opened.matches('[data-media-dropdown]') && opened.open) {
        for (const other of dropdowns()) {
            if (other !== opened) {
                other.open = false;
            }
        }
    }
}, true);

document.addEventListener('click', (event) => {
    for (const menu of dropdowns()) {
        if (menu.open && !(event.target instanceof Node && menu.contains(event.target))) {
            menu.open = false;
        }
    }
});

// Escape closes an open dropdown before anything else (a modal picker would close too).
document.addEventListener('keydown', (event) => {
    const open = dropdowns().find((menu) => menu.open);
    if (event.key === 'Escape' && open !== undefined) {
        event.preventDefault();
        event.stopPropagation();
        open.open = false;
        open.querySelector('summary').focus();
    }
}, true);

function showChoice(input) {
    const menu = input.closest('[data-media-dropdown]');
    const option = input.closest('label');
    if (menu === null || option === null) {
        return;
    }
    const summary = menu.querySelector('summary');
    const show = summary.dataset.dropdownShow;
    const parts = [];
    if (show !== 'text') {
        parts.push(...option.querySelectorAll('[data-option-icon]'));
    }
    if (show !== 'icon') {
        parts.push(...option.querySelectorAll('[data-option-text]'));
    }
    summary.querySelector('[data-dropdown-current]').replaceChildren(...parts.map((part) => part.cloneNode(true)));
    const name = `${summary.dataset.dropdownLabel}: ${option.querySelector('[data-option-text]').textContent}`;
    summary.title = name;
    summary.ariaLabel = name;
    menu.open = false;
}

// A theme colour preset (settings, [data-color-presets]) goes into the field's colour input and turns the colour on;
// the preset radios themselves are never stored.
function usePreset(input) {
    const field = input.closest('[data-color-presets]')?.closest('.admin-color-field');
    const color = field?.querySelector('input[type="color"]');
    if (!(color instanceof HTMLInputElement)) {
        return;
    }
    color.value = input.value;
    color.dispatchEvent(new Event('input', { bubbles: true }));
    color.dispatchEvent(new Event('change', { bubbles: true }));
    const on = field.querySelector('input[type="checkbox"]');
    if (on instanceof HTMLInputElement && !on.checked) {
        on.checked = true;
        on.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

// The filter forms submit themselves through htmx (only the results are swapped, so the search box keeps its focus):
// on any change of a dropdown or switch, and 300 ms after typing in the search box once it holds 3 or more characters
// or was emptied. Their submit button is hidden under "js".
const searchTimers = new WeakMap();

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) {
        return;
    }
    if (input.type === 'radio') {
        showChoice(input);
        usePreset(input);
    }
    // A dropdown outside a filter form (the Profile's admin colour, the settings' toolbar corner) only shows the
    // choice; the form's Save stores it.
    const form = input.closest('[data-media-filters]');
    if (form === null || input.matches('[data-media-search]')) {
        return;
    }
    form.requestSubmit();
});

document.addEventListener('input', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-media-search]')) {
        return;
    }
    clearTimeout(searchTimers.get(input));
    const length = input.value.trim().length;
    if (length > 2 || length === 0) {
        searchTimers.set(input, setTimeout(() => input.form?.requestSubmit(), 300));
    }
});

countChecked();
document.addEventListener('htmx:afterSettle', countChecked);
document.addEventListener('change', (event) => {
    if (event.target instanceof HTMLInputElement && event.target.form?.matches('[data-media-bulk]')) {
        countChecked();
    }
});

// The media item page's "Copy link" (rendered hidden, shown where the clipboard is available): the address is copied
// and a toast cloned from the page's templates says so.
for (const button of document.querySelectorAll('[data-copy-link]')) {
    button.hidden = !navigator.clipboard;
    button.addEventListener('click', () => {
        const report = (selector) => {
            const toast = document.querySelector(selector)?.content.firstElementChild ?? null;
            if (toast !== null) {
                document.querySelector('[data-toasts]')?.append(toast.cloneNode(true));
            }
        };
        navigator.clipboard.writeText(button.dataset.copyLink).then(
            () => report('template[data-copy-toast]'),
            () => report('template[data-copy-failed]'),
        );
    });
}

// The upload forms, found from any event inside them (htmx events fire on the form itself).
const uploadForm = (event) => (event.target instanceof Element ? event.target.closest('[data-media-dropzone]') : null);

// Drop zones (the library's upload form and the picker's): dropped files go into the form's file input and the
// form is submitted, through htmx.
const dropzone = (event) => (event.target instanceof Element ? event.target.closest('[data-media-dropzone]') : null);

document.addEventListener('dragover', (event) => {
    const zone = dropzone(event);
    if (zone !== null) {
        event.preventDefault();
        zone.classList.add('admin-media-dragging');
    }
});

document.addEventListener('dragleave', (event) => {
    dropzone(event)?.classList.remove('admin-media-dragging');
});

document.addEventListener('drop', (event) => {
    const zone = dropzone(event);
    if (zone === null) {
        return;
    }
    event.preventDefault();
    zone.classList.remove('admin-media-dragging');
    if (event.dataTransfer !== null && event.dataTransfer.files.length > 0) {
        zone.querySelector('input[type="file"]').files = event.dataTransfer.files;
        zone.requestSubmit();
    }
});

// Choosing files in the file dialog uploads them at once, like a drop; the submit button is hidden under "js".
document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || input.files.length === 0) {
        return;
    }
    const form = input.closest('[data-media-dropzone]');
    if (form !== null) {
        form.requestSubmit();
    }
});

// Upload progress: htmx reports the request's upload progress on the form; its <progress> shows it until the
// response arrives.
const progressOf = (event) => uploadForm(event)?.querySelector('[data-upload-progress]') ?? null;

document.addEventListener('htmx:beforeRequest', (event) => {
    const progress = progressOf(event);
    if (progress !== null) {
        progress.value = 0;
        progress.hidden = false;
    }
});

document.addEventListener('htmx:xhr:progress', (event) => {
    const progress = progressOf(event);
    if (progress !== null && event.detail.lengthComputable) {
        // htmx reports the download phase too: the bar only ever moves forward.
        progress.value = Math.max(progress.value, Math.round((event.detail.loaded / event.detail.total) * 100));
    }
});

document.addEventListener('htmx:afterRequest', (event) => {
    const progress = progressOf(event);
    if (progress !== null) {
        progress.hidden = true;
    }
});

// The toast stack for a message about an element: a modal dialog lies above the page, so the picker uses its own.
const stackFor = (element) => element.closest('dialog')?.querySelector('[data-toasts]') ?? document.querySelector('[data-toasts]');

// After a successful upload htmx has swapped in a fresh form. After a failed one (expired session, request too
// large, server error) htmx swaps nothing: show the form's error toast (cloned from its <template data-upload-failed>)
// and clear the file input, so choosing the same files again uploads them (an input keeping its files fires no
// "change" for the same choice; the submit button is hidden under "js").
function showUploadError(event) {
    const form = uploadForm(event);
    if (form === null) {
        return;
    }
    const toast = form.querySelector('template[data-upload-failed]')?.content.firstElementChild ?? null;
    if (toast !== null) {
        stackFor(form)?.append(toast.cloneNode(true));
    }
    form.querySelector('input[type="file"]').value = '';
}

document.addEventListener('htmx:responseError', showUploadError);
document.addEventListener('htmx:sendError', showUploadError);

document.addEventListener('htmx:afterRequest', (event) => {
    const form = uploadForm(event);
    if (form !== null && form.isConnected && event.detail.successful) {
        form.reset();
    }
});

// An upload's results arrive as toasts inside a <template data-upload-toast> of the swapped fragment (a success and
// one error per refused file): move them into the toast stack (shell.js wires and hides them like flashes).
document.addEventListener('htmx:afterSettle', () => {
    for (const template of document.querySelectorAll('template[data-upload-toast]')) {
        stackFor(template)?.append(...template.content.children);
        template.remove();
    }
});
