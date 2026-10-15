// The tag box of a tags field (fields/tags.php, Plan 35), after Tom Select's tagging example. Without JavaScript the
// field is its comma-separated text input; here that input (data-tags-value) is hidden and stays the posted value,
// always the pills joined with ", ", and every add or remove fires "input" and "change" on it, so autosave.js and the
// "unsaved changes" check hear them (the query's own events stop at the box: typing alone changes nothing). The query
// is an ARIA 1.2 combobox with a listbox popup: typing filters the taxonomy's tags of the entry's language
// (data-tags-names), case- and accent-insensitively like the database collation, and offers "Add “x”" for a new
// name. Pills and options are clones of the widget's <template>s, filled through textContent and properties only
// (CSP, no markup injection, no requests).

// At most this many suggestions show; typing narrows them.
const MAX_SHOWN = 50;

// Case- and accent-insensitive, like utf8mb4_unicode_ci: "Café" = "cafe", "Ё" = "е", Hebrew points ignored.
const fold = (text, lang) => text.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase(lang || undefined);

// As TagsType::cleanName(): runs of whitespace and control characters become one space, the ends are trimmed.
const clean = (text) => text.replace(/[\s\x00-\x1F\x7F]+/gu, ' ').trim();

// The server's texts carry "#" for the name.
const fill = (text, name) => (text ?? '').replace('#', () => name);

const cloneOf = (template) => template.content.firstElementChild.cloneNode(true);

function setUp(widget) {
    const field = widget.parentElement;
    const carrier = field.querySelector('[data-tags-value]');
    const list = widget.querySelector('[data-tags-list]');
    const box = widget.querySelector('[data-tags-box]');
    const query = widget.querySelector('[data-tags-query]');
    const listbox = widget.querySelector('[data-tags-listbox]');
    const status = widget.querySelector('[data-tags-status]');
    const full = widget.querySelector('[data-tags-full]');
    const templates = {
        pill: widget.querySelector('template[data-tags-pill]'),
        option: widget.querySelector('template[data-tags-option]'),
        create: widget.querySelector('template[data-tags-create]'),
    };
    if (!carrier || !list || !box || !query || !listbox || !status || !templates.pill || !templates.option || !templates.create) {
        return;
    }
    const lang = widget.lang;
    const texts = widget.dataset;
    const max = texts.max ? Number(texts.max) : Infinity;
    const required = 'tagsRequired' in texts;

    // The existing tags by their folded name, in the server's order (by name); names that fold alike are offered once.
    const known = new Map();
    let names = [];
    try {
        names = JSON.parse(texts.tagsNames || '[]');
    } catch {
        names = [];
    }
    for (const name of Array.isArray(names) ? names : []) {
        if (typeof name === 'string' && clean(name) !== '' && !known.has(fold(name, lang))) {
            known.set(fold(name, lang), name);
        }
    }

    let chosen = [];
    let options = [];
    let active = -1;

    const isChosen = (key) => chosen.some((name) => fold(name, lang) === key);

    // A live region announces a change only: emptied first, so the same text twice is read twice.
    const announce = (text) => {
        status.textContent = '';
        requestAnimationFrame(() => {
            status.textContent = text;
        });
    };

    const pill = (name) => {
        const node = cloneOf(templates.pill);
        node.querySelector('.admin-tags-pill-text').textContent = name;
        const button = node.querySelector('[data-tags-remove]');
        button.ariaLabel = fill(texts.remove, name);
        button.title = fill(texts.remove, name);
        return node;
    };

    // The carrier follows the pills; a full box takes no more (its reason shows), a required one wants a pill.
    const sync = (notify) => {
        carrier.value = chosen.join(', ');
        const isFull = chosen.length >= max;
        query.readOnly = isFull;
        if (full) {
            // The query names this line in aria-describedby, which reads a hidden element's text too: text only when full.
            full.textContent = isFull ? texts.full ?? '' : '';
            full.hidden = !isFull;
        }
        query.required = required && chosen.length === 0;
        if (notify) {
            carrier.dispatchEvent(new Event('input', { bubbles: true }));
            carrier.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    const close = () => {
        listbox.hidden = true;
        listbox.replaceChildren();
        options = [];
        active = -1;
        query.ariaExpanded = 'false';
        query.ariaActiveDescendantElement = null;
    };

    const highlight = (index) => {
        options.forEach((option, i) => {
            option.element.classList.toggle('admin-tags-option-active', i === index);
            option.element.ariaSelected = i === index ? 'true' : 'false';
        });
        active = index;
        query.ariaActiveDescendantElement = index >= 0 ? options[index].element : null;
        options[index]?.element.scrollIntoView({ block: 'nearest' });
    };

    // An existing tag keeps its spelling; one already chosen is ignored; nothing past the field's maximum.
    const add = (raw) => {
        const typed = clean(raw);
        if (typed === '') {
            return;
        }
        const key = fold(typed, lang);
        const name = known.get(key) ?? typed;
        if (isChosen(key)) {
            announce(fill(texts.duplicate, name));
            return;
        }
        if (chosen.length >= max) {
            announce(texts.full ?? '');
            return;
        }
        chosen.push(name);
        list.append(pill(name));
        sync(true);
        announce(chosen.length >= max ? fill(texts.added, name) + ' ' + (texts.full ?? '') : fill(texts.added, name));
    };

    const remove = (index) => {
        const name = chosen[index];
        if (name === undefined) {
            return;
        }
        chosen.splice(index, 1);
        list.children[index]?.remove();
        sync(true);
        announce(fill(texts.removed, name));
    };

    // The suggestions for the query: tags that start with it first, then those that contain it, the chosen ones left
    // out; "Add “x”" first for a name no tag has. After typing (or ArrowDown) the first tag that starts with the query is
    // highlighted (a typo must not create a near-duplicate), else "Add" (typing "art" must not pick "smart"), else the
    // first; a press in the box highlights nothing, so Enter there picks nothing.
    const open = (highlightFirst) => {
        if (query.readOnly) {
            close();
            return;
        }
        const typed = clean(query.value);
        const key = fold(typed, lang);
        const starts = [];
        const contains = [];
        for (const [folded, name] of known) {
            if (isChosen(folded)) {
                continue;
            }
            if (folded.startsWith(key)) {
                starts.push(name);
            } else if (folded.includes(key)) {
                contains.push(name);
            }
        }
        const matches = [...starts, ...contains].slice(0, MAX_SHOWN);
        const items = [];
        if (typed !== '' && !known.has(key) && !isChosen(key)) {
            const element = cloneOf(templates.create);
            element.querySelector('[data-tags-text]').textContent = fill(texts.add, typed);
            items.push({ element, name: typed });
        }
        for (const name of matches) {
            const element = cloneOf(templates.option);
            element.querySelector('[data-tags-text]').textContent = name;
            items.push({ element, name });
        }
        if (items.length === 0) {
            close();
            return;
        }
        items.forEach((item, i) => {
            item.element.id = listbox.id + '-' + i;
        });
        listbox.replaceChildren(...items.map((item) => item.element));
        options = items;
        listbox.hidden = false;
        query.ariaExpanded = 'true';
        const withAdd = items.length > matches.length;
        highlight(!highlightFirst ? -1 : withAdd && starts.length > 0 ? 1 : 0);
    };

    const pick = (index) => {
        const option = options[index];
        query.value = '';
        close();
        if (option) {
            add(option.name);
        }
    };

    // The typed text, split at commas and line breaks: every part but the last is added, the last stays typed.
    const split = (text, keepLast) => {
        const parts = text.split(/[,\r\n]+/);
        const last = keepLast ? parts.pop() ?? '' : '';
        parts.forEach(add);
        // A full box takes no more: what is left is dropped, not kept in a query that cannot be added.
        return chosen.length >= max ? '' : last;
    };

    const commit = () => {
        if (clean(query.value) === '') {
            return;
        }
        const text = query.value;
        query.value = '';
        close();
        split(text, false);
    };

    // The value the page was served with (or the browser restored) is the truth: the pills are rebuilt from it.
    for (const name of carrier.value.split(',')) {
        const typed = clean(name);
        if (typed !== '' && !isChosen(fold(typed, lang))) {
            chosen.push(typed);
        }
    }
    list.replaceChildren(...chosen.map(pill));
    carrier.required = false;
    sync(false);
    for (const label of field.querySelectorAll('label')) {
        if (label.htmlFor === carrier.id) {
            label.htmlFor = query.id;
        }
    }

    query.addEventListener('input', (event) => {
        event.stopPropagation();
        // An IME composition is never split or filtered half-way; its last input event (not composing) does it.
        if (event.isComposing) {
            return;
        }
        if (/[,\r\n]/.test(query.value)) {
            query.value = split(query.value, true);
        }
        if (clean(query.value) === '') {
            close();
        } else {
            open(true);
        }
    });
    query.addEventListener('change', (event) => event.stopPropagation());
    query.addEventListener('paste', (event) => {
        const pasted = event.clipboardData?.getData('text') ?? '';
        if (!/[,\r\n]/.test(pasted)) {
            return;
        }
        event.preventDefault();
        const start = query.selectionStart ?? query.value.length;
        const end = query.selectionEnd ?? start;
        // What stays typed keeps the query's own length limit (a paste bypasses maxlength here).
        query.value = split(query.value.slice(0, start) + pasted + query.value.slice(end), true).slice(0, query.maxLength);
        if (clean(query.value) === '') {
            close();
        } else {
            open(true);
        }
    });
    query.addEventListener('keydown', (event) => {
        // Safari ends a composition with an Enter whose isComposing is false but keyCode 229.
        if (event.isComposing || event.keyCode === 229) {
            return;
        }
        const count = options.length;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (listbox.hidden) {
                open(true);
            } else if (count > 0) {
                highlight((active + 1) % count);
            }
        } else if (event.key === 'ArrowUp') {
            if (!listbox.hidden && count > 0) {
                event.preventDefault();
                highlight(active < 0 ? count - 1 : (active - 1 + count) % count);
            }
        } else if (event.key === 'Enter') {
            if (!listbox.hidden && active >= 0) {
                event.preventDefault();
                pick(active);
            } else if (clean(query.value) !== '') {
                event.preventDefault();
                commit();
            } else if (!listbox.hidden) {
                // The list a press opened, nothing chosen in it: Enter closes it rather than submitting the form.
                event.preventDefault();
                close();
            }
        } else if (event.key === 'Escape') {
            if (!listbox.hidden) {
                event.preventDefault();
                close();
            } else if (query.value !== '') {
                event.preventDefault();
                query.value = '';
            }
        } else if (event.key === 'Backspace') {
            if (query.value === '' && chosen.length > 0) {
                event.preventDefault();
                remove(chosen.length - 1);
            }
        }
    });
    // Leaving the query (Tab, a click on Save) adds what was typed: nothing typed is lost.
    // Switching to another window or the developer tools blurs too: that adds nothing (the text waits for the return).
    query.addEventListener('blur', () => {
        if (!document.hasFocus()) {
            return;
        }
        commit();
        close();
    });

    // A press on an option keeps the focus in the query; the click adds it.
    listbox.addEventListener('pointerdown', (event) => event.preventDefault());
    listbox.addEventListener('click', (event) => {
        const option = event.target instanceof Element ? event.target.closest('[role="option"]') : null;
        const index = options.findIndex((item) => item.element === option);
        if (index >= 0) {
            pick(index);
            query.focus();
        }
    });
    listbox.addEventListener('pointermove', (event) => {
        const option = event.target instanceof Element ? event.target.closest('[role="option"]') : null;
        const index = options.findIndex((item) => item.element === option);
        if (index >= 0 && index !== active) {
            highlight(index);
        }
    });

    // × removes its pill; a press anywhere else in the box goes to the query and opens the suggestions.
    box.addEventListener('click', (event) => {
        if (event.target instanceof Node && listbox.contains(event.target)) {
            return;
        }
        const button = event.target instanceof Element ? event.target.closest('[data-tags-remove]') : null;
        if (button) {
            remove([...list.children].indexOf(button.closest('li')));
            query.focus();
            return;
        }
        if (event.target !== query) {
            query.focus();
        }
        open(clean(query.value) !== '');
    });
    document.addEventListener('pointerdown', (event) => {
        if (!listbox.hidden && event.target instanceof Node && !widget.contains(event.target)) {
            close();
        }
    });
}

for (const widget of document.querySelectorAll('[data-tags]')) {
    setUp(widget);
}
