// The tab columns of the entry form (two) and of the section form (one: Main and SEO). Without JavaScript every panel
// is shown under its heading (admin.css hides the tab lists); this module turns each [data-tabs] column into tabs
// after the ARIA tabs pattern (arrow keys, Home, End), remembers the open tab of each column in localStorage under the
// column's data-tabs key (an empty key, the section form's and the map dialog's, remembers nothing: the tab the server
// opened shows), and after a failed save opens the first tab with errors instead. Elements of the column outside the
// panels marked data-tab-for="{tab}" show with that tab only. An input the browser finds invalid on submit gets its tab opened, so the browser can
// show its message. The publication date is hidden while the status is "Draft". Until a column is set up
// ("admin-tabs-ready"), admin.css shows only the panel the server opened, so the page does not flash. A form that
// arrives later (the section form in the Sections screen's dialog, loaded by htmx) is set up once htmx has settled it.

// localStorage may be unavailable (private windows, blocked site data): the tab then resets on the next page.
function read(key) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function store(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Nothing to do: see above.
    }
}

const KEYS = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
// The left and right arrows follow the row of tabs, which runs from right to left in a right-to-left page (read from
// the computed direction, which every browser has; the dir pseudo-class is recent).
const horizontal = (key) => key === 'ArrowLeft' || key === 'ArrowRight';
const FIRST_INVALID = 'input:invalid, select:invalid, textarea:invalid';

const setUp = (column) => {
    const key = column.dataset.tabs;
    const tabs = [...column.querySelectorAll('[role="tab"]')];
    const panels = tabs.map((tab) => document.getElementById(tab.getAttribute('aria-controls')));
    if (tabs.length === 0 || panels.includes(null)) {
        return;
    }

    // Elements outside the panels that belong to one tab (data-tab-for: the map dialog's "Save map" of each tab's form).
    const followers = [...column.querySelectorAll('[data-tab-for]')];
    const select = (index, focus) => {
        tabs.forEach((tab, i) => {
            tab.ariaSelected = String(i === index);
            tab.tabIndex = i === index ? 0 : -1;
            panels[i].hidden = i !== index;
        });
        for (const follower of followers) {
            follower.hidden = follower.dataset.tabFor !== tabs[index].dataset.tab;
        }
        if (focus) {
            tabs[index].focus();
        }
    };
    const open = (index, focus) => {
        select(index, focus);
        if (key !== '') {
            store(key, tabs[index].dataset.tab);
        }
    };

    const withErrors = tabs.findIndex((tab) => Number(tab.dataset.errors) > 0);
    const remembered = key === '' ? -1 : tabs.findIndex((tab) => tab.dataset.tab === read(key));
    // Else the tab the server opened (the map dialog opens on the way the map was entered).
    const opened = tabs.findIndex((tab) => tab.ariaSelected === 'true');
    select(withErrors >= 0 ? withErrors : (remembered >= 0 ? remembered : Math.max(opened, 0)), false);
    // From now on the panels follow their hidden property (until here admin.css showed only the server's open one).
    column.classList.add('admin-tabs-ready');

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => open(index, false));
        tab.addEventListener('keydown', (event) => {
            if (event.altKey || event.ctrlKey || event.metaKey) {
                return;
            }
            let next = null;
            if (event.key in KEYS) {
                const step = KEYS[event.key] * (horizontal(event.key) && getComputedStyle(tab).direction === 'rtl' ? -1 : 1);
                next = (index + step + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = tabs.length - 1;
            }
            if (next !== null) {
                event.preventDefault();
                open(next, true);
            }
        });
    });

    // "invalid" does not bubble; listen in the capture phase. On submit the browser fires it for every invalid control
    // in tree order and focuses only the first, so only that one may open its tab (else the last would win and the
    // first control would stay in a hidden panel, unfocusable).
    column.closest('form')?.addEventListener('invalid', (event) => {
        const form = event.currentTarget;
        if (!(event.target instanceof Element) || event.target !== form.querySelector(FIRST_INVALID)) {
            return;
        }
        const panel = event.target.closest('[role="tabpanel"]');
        const index = panels.indexOf(panel);
        if (index >= 0 && panel.hidden) {
            select(index, false);
        }
    }, true);
};

const setUpAll = () => {
    for (const column of document.querySelectorAll('[data-tabs]:not(.admin-tabs-ready)')) {
        setUp(column);
    }
};
setUpAll();
// The Sections screen's dialog (dialog.js) loads the section form through htmx after this module ran.
document.addEventListener('htmx:afterSettle', setUpAll);

const dates = document.querySelectorAll('[data-status-date]');
const choices = document.querySelectorAll('[data-status-choice]');
const syncDate = () => {
    const checked = [...choices].find((choice) => choice.checked);
    for (const date of dates) {
        date.hidden = checked !== undefined && checked.value === 'draft';
    }
};
// Choosing "Published" with an empty publication date fills in the current site time (the server would store the same).
const fillNow = (choice) => {
    if (!choice.checked || choice.value !== 'published') {
        return;
    }
    for (const date of dates) {
        const input = date.querySelector('input[data-now]');
        if (input !== null && input.value === '') {
            input.value = input.dataset.now;
        }
    }
};
for (const choice of choices) {
    choice.addEventListener('change', () => {
        syncDate();
        fillNow(choice);
    });
}
syncDate();
