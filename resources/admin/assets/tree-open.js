// Opens the remembered items of the admin tree editor's accordion before the first paint (tree.js, a module, runs only
// after the page is parsed, so opening them there would move the rows below them). A classic script, not a module: the
// tree editor loads it right before its list, so the parser waits for it, and its MutationObserver sees every item as
// the parser inserts it; observer callbacks run before the browser renders, so an item is already open when it is first
// painted. It only reads the ids tree.js stores under admin.tree.open:{form action}:{locale} (the same key) and adds
// admin-tree-open with aria-expanded; tree.js does everything else and stores the state.
(() => {
    const form = document.currentScript?.closest('[data-tree-form]');
    if (!form) {
        return;
    }
    const storageKey = 'admin.tree.open:' + form.getAttribute('action') + ':' + form.elements.namedItem('locale')?.value;
    let opened = [];
    try {
        const stored = JSON.parse(localStorage.getItem(storageKey) ?? '[]');
        opened = Array.isArray(stored) ? stored.map(String) : [];
    } catch {
        opened = [];
    }
    if (opened.length === 0) {
        return;
    }
    const open = () => {
        form.querySelectorAll('[data-id]').forEach((item) => {
            if (opened.includes(item.dataset.id)) {
                item.classList.add('admin-tree-open');
                const toggle = item.querySelector(':scope > .admin-tree-row [data-tree-toggle]');
                if (toggle !== null) {
                    toggle.ariaExpanded = 'true';
                }
            }
        });
    };
    const observer = new MutationObserver(open);
    observer.observe(form, { childList: true, subtree: true });
    document.addEventListener('DOMContentLoaded', () => {
        observer.disconnect();
        open();
    }, { once: true });
})();
