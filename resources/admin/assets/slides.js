// The slides widget of the entry form (fields/slides.php) and its dialog (slides/dialog.php). Without JavaScript each
// slide is a fieldset of its inputs and the server applies the order and delete boxes. Here the fieldsets hide behind
// compact rows (admin.css, under .js; a slide with a video shows a video marker): the rows move with their arrow
// buttons or by dragging their handle (the vendored
// SortableJS, like tree.js), Delete removes a slide, and Add slide / Edit open the field's dialog, whose Done copies
// its values into the slide's own inputs (a new slide is cloned from the widget's <template>). Nothing is sent before
// the entry's Save: every change fires "change" on the widget, so autosave.js hears it. DOM is changed through
// properties, textContent and cloned server markup only (CSP, no markup injection).
import Sortable from './vendor/sortable-1.15.7.esm.js';

const TEXTS = ['title', 'subtitle', 'url', 'button'];
const INDEX = '__index__';

const itemsOf = (widget) => [...widget.querySelector('[data-slides-list]').children].filter((item) => item.matches('[data-slides-item]'));
const inputOf = (item, part) => item.querySelector('[data-slides-input="' + part + '"]');

// A script's change to an input fires no event: "change" tells the entry form (autosave.js) that it has changed.
const changed = (widget) => widget.dispatchEvent(new Event('change', { bubbles: true }));

// The order inputs follow the rows (the server sorts by them), the moves that would change nothing are disabled, the
// empty state shows without slides and Add stops at the field's maximum.
function refresh(widget) {
    const items = itemsOf(widget);
    items.forEach((item, index) => {
        inputOf(item, 'position').value = String(index + 1);
        item.querySelector('[data-slides-up]').disabled = index === 0;
        item.querySelector('[data-slides-down]').disabled = index === items.length - 1;
    });
    widget.querySelector('[data-slides-empty]').hidden = items.length > 0;
    widget.querySelector('[data-slides-add]').disabled = items.length >= Number(widget.dataset.max);
}

// The row shows the slide's title (or "Untitled slide") and subtitle.
function show(widget, item) {
    const title = inputOf(item, 'title').value.trim();
    item.querySelector('[data-slides-title]').textContent = title === '' ? widget.dataset.untitled : title;
    item.querySelector('[data-slides-subtitle]').textContent = inputOf(item, 'subtitle').value.trim();
}

// A new slide: the template's item with "__index__" in its names and ids replaced by the next free index.
function newItem(widget) {
    const item = widget.querySelector('template[data-slides-template]').content.firstElementChild.cloneNode(true);
    const index = widget.dataset.next;
    widget.dataset.next = String(Number(index) + 1);
    for (const element of item.querySelectorAll('[name]')) {
        element.name = element.name.replace(INDEX, index);
    }
    for (const element of item.querySelectorAll('[id]')) {
        element.id = element.id.replace(INDEX, index);
    }
    for (const label of item.querySelectorAll('label[for]')) {
        label.htmlFor = label.htmlFor.replace(INDEX, index);
    }

    return item;
}

const dialogs = new Map();
let editing = null; // { widget, item (null for a new slide), opener }

// The dialog's two media widgets: the picture (required) and the video (optional, its picker lists videos only).
const MEDIA = ['image', 'video'];
const mediaWidget = (dialog, part) => dialog.querySelector('[data-slides-media="' + part + '"] [data-media-field]');
const chosen = (dialog, part) => mediaWidget(dialog, part).querySelector('[data-media-chosen]');
const idsInput = (dialog, part) => mediaWidget(dialog, part).querySelector('[data-media-ids]');
// A video's card carries no visible name (it is a player): the name travels in data-name.
const pickedName = (dialog, part) => {
    const entry = chosen(dialog, part).querySelector('[data-id]');

    return entry?.dataset.name ?? entry?.querySelector('.admin-media-name')?.textContent ?? '';
};

// Puts a slide's file into one of the dialog's media widgets as media.js shows a chosen file: the picture's
// thumbnail and name, a video's player card, and the remove button cloned from the widget's own template.
function setMedia(dialog, part, id, thumb, name, url = '') {
    const list = chosen(dialog, part);
    list.replaceChildren();
    idsInput(dialog, part).value = id;
    if (id === '') {
        return;
    }
    const entry = document.createElement('li');
    entry.dataset.id = id;
    entry.dataset.name = name;
    if (url !== '') {
        entry.dataset.url = url;
    }
    if (part === 'video' && url !== '') {
        const video = document.createElement('video');
        video.className = 'admin-media-video';
        video.controls = true;
        video.preload = 'metadata';
        video.src = url;
        entry.append(video);
    } else {
        if (thumb !== null) {
            entry.append(thumb.cloneNode(true));
        }
        const label = document.createElement('span');
        label.className = 'admin-media-name';
        label.textContent = name || '#' + id;
        label.title = label.textContent;
        entry.append(label);
    }
    entry.append(mediaWidget(dialog, part).querySelector('[data-media-remove-template]').content.firstElementChild.cloneNode(true));
    list.append(entry);
}

// The row's video marker: shown for a slide with a video and named after it ("Video: waves.mp4").
function showVideo(widget, item) {
    const id = inputOf(item, 'video').value.trim();
    const label = id === '' ? '' : widget.dataset.hasVideo.replace('#', () => item.dataset.videoFile || '#' + id);
    const marker = item.querySelector('[data-slides-video]');
    marker.hidden = id === '';
    marker.title = label;
    marker.ariaLabel = label;
}

function open(widget, item, opener) {
    const dialog = dialogs.get(widget.dataset.slides);
    editing = { widget, item, opener };
    dialog.querySelector('h2').textContent = item === null ? dialog.dataset.titleAdd : dialog.dataset.titleEdit;
    for (const part of TEXTS) {
        dialog.querySelector('[data-slides-field="' + part + '"]').value = item === null ? '' : inputOf(item, part).value;
    }
    for (const part of MEDIA) {
        setMedia(
            dialog,
            part,
            item === null ? '' : inputOf(item, part).value.trim(),
            part === 'image' ? item?.querySelector('[data-slides-preview] img') ?? null : null,
            (part === 'image' ? item?.dataset.file : item?.dataset.videoFile) ?? '',
            part === 'video' ? item?.dataset.videoUrl ?? '' : '',
        );
    }
    dialog.querySelector('[data-slides-image-error]').hidden = true;
    idsInput(dialog, 'image').ariaInvalid = null;
    dialog.showModal();
}

// Done: the dialog's values go into the slide's inputs and row; a slide needs an image, its video is optional (the
// server checks that it is a video).
function done(dialog) {
    const { widget } = editing;
    let { item } = editing;
    const id = idsInput(dialog, 'image').value.trim();
    if (!/^[1-9]\d{0,9}$/.test(id)) {
        dialog.querySelector('[data-slides-image-error]').hidden = false;
        idsInput(dialog, 'image').ariaInvalid = 'true';
        (mediaWidget(dialog, 'image').querySelector('[data-media-choose]') ?? idsInput(dialog, 'image')).focus();

        return;
    }
    if (item === null) {
        item = newItem(widget);
        widget.querySelector('[data-slides-list]').append(item);
    }
    inputOf(item, 'image').value = id;
    inputOf(item, 'video').value = idsInput(dialog, 'video').value.trim();
    for (const part of TEXTS) {
        inputOf(item, part).value = dialog.querySelector('[data-slides-field="' + part + '"]').value.trim();
    }
    const thumb = chosen(dialog, 'image').querySelector('[data-id] img');
    item.querySelector('[data-slides-preview]').replaceChildren(...(thumb === null ? [] : [thumb.cloneNode(true)]));
    item.dataset.file = pickedName(dialog, 'image');
    item.dataset.videoFile = pickedName(dialog, 'video');
    item.dataset.videoUrl = chosen(dialog, 'video').querySelector('[data-id]')?.dataset.url ?? '';
    showVideo(widget, item);
    show(widget, item);
    refresh(widget);
    changed(widget);
    // The close handler leaves the focus alone: it goes to the slide's Edit button.
    editing = null;
    dialog.close();
    item.querySelector('[data-slides-edit]').focus();
}

function move(widget, item, down) {
    const sibling = down ? item.nextElementSibling : item.previousElementSibling;
    if (sibling === null || !sibling.matches('[data-slides-item]')) {
        return;
    }
    if (down) {
        sibling.after(item);
    } else {
        sibling.before(item);
    }
    refresh(widget);
    changed(widget);
    // The pressed arrow may now be disabled (a first or last row): the other one keeps the focus.
    const pressed = item.querySelector(down ? '[data-slides-down]' : '[data-slides-up]');
    (pressed.disabled ? item.querySelector(down ? '[data-slides-up]' : '[data-slides-down]') : pressed).focus();
}

function remove(widget, item) {
    const next = item.nextElementSibling ?? item.previousElementSibling;
    item.remove();
    refresh(widget);
    changed(widget);
    (next?.querySelector('[data-slides-edit]') ?? widget.querySelector('[data-slides-add]')).focus();
}

for (const dialog of document.querySelectorAll('[data-slides-dialog]')) {
    dialogs.set(dialog.dataset.slidesDialog, dialog);
    dialog.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (target?.closest('[data-slides-done]')) {
            done(dialog);
        } else if (target?.closest('[data-slides-cancel]')) {
            dialog.close();
        }
    });
    // Escape, Close and Cancel drop the dialog's values; the focus goes back to the button that opened it.
    dialog.addEventListener('close', () => {
        dialog.querySelector('[data-slides-media="video"] video')?.pause();
        if (editing !== null) {
            const { opener } = editing;
            editing = null;
            if (opener?.isConnected) {
                opener.focus();
            }
        }
    });
}

for (const widget of document.querySelectorAll('[data-slides]')) {
    if (!dialogs.has(widget.dataset.slides)) {
        continue;
    }
    // The no-JS "new slide" group: Add slide replaces it.
    for (const blank of widget.querySelectorAll('[data-slides-blank]')) {
        blank.remove();
    }
    widget.querySelector('[data-slides-add]').hidden = false;
    refresh(widget);
    Sortable.create(widget.querySelector('[data-slides-list]'), {
        handle: '[data-slides-handle]',
        draggable: '[data-slides-item]',
        animation: 150,
        onEnd: () => {
            refresh(widget);
            changed(widget);
        },
    });
    // A script that changes a slide's input (translate.js, the machine translation) fires "input": the row follows.
    widget.addEventListener('input', (event) => {
        const input = event.target instanceof Element ? event.target.closest('[data-slides-input]') : null;
        const item = input?.closest('[data-slides-item]') ?? null;
        if (item !== null && widget.contains(item)) {
            show(widget, item);
        }
    });
    widget.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const button = target?.closest('button');
        if (!button || !widget.contains(button)) {
            return;
        }
        const item = button.closest('[data-slides-item]');
        if (button.matches('[data-slides-add]')) {
            open(widget, null, button);
        } else if (item === null) {
            return;
        } else if (button.matches('[data-slides-edit]')) {
            open(widget, item, button);
        } else if (button.matches('[data-slides-delete]')) {
            remove(widget, item);
        } else if (button.matches('[data-slides-up]')) {
            move(widget, item, false);
        } else if (button.matches('[data-slides-down]')) {
            move(widget, item, true);
        }
    });
}
