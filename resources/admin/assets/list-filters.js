// The filter forms of the content lists ([data-list-filters]) submit themselves through htmx, which swaps only the
// results (so the search box keeps its focus): on any change of a dropdown, and 300 ms after typing in the search box
// ([data-list-search]) once it holds 2 or more characters or was emptied. Their submit button (.admin-list-apply) is
// hidden under "js"; without JavaScript it submits the form as a normal GET.
const DELAY = 300;
const MIN_LENGTH = 2;
const timers = new WeakMap();

document.addEventListener('change', (event) => {
    const field = event.target;
    if (!(field instanceof HTMLSelectElement)) {
        return;
    }
    field.closest('[data-list-filters]')?.requestSubmit();
});

document.addEventListener('input', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-list-search]')) {
        return;
    }
    clearTimeout(timers.get(input));
    const length = input.value.trim().length;
    if (length >= MIN_LENGTH || length === 0) {
        timers.set(input, setTimeout(() => input.form?.requestSubmit(), DELAY));
    }
});
