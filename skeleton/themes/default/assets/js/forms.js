// Site forms in a dialog (partials/form-dialog.php). A click on a link with data-form-dialog="form-{key}" opens that
// dialog as a modal (the focus goes to the first field, and back to the link when it closes); without the dialog on
// the page (the form's own page) the link is followed. The form is sent with fetch to its own action, asking for JSON:
// the server's texts (already in the page's language) go into the prepared paragraphs with textContent, the fields
// get aria-invalid and the first invalid one the focus; the typed values stay after a refusal, and a refused form
// takes the fresh token the answer carries. After a success the fields hide, the thanks shows in the status
// paragraph and the form is reset (only reset when the dialog was closed meanwhile); closing the dialog brings the
// fields back. A network failure shows the dialog's
// data-network-error text. Classes, properties and textContent only (no markup, no styles, no other URLs).

const openers = new WeakMap();

// The visitor's fields in the form's order: not the token, not the honeypot.
const fieldsOf = (form) => [...form.elements].filter((element) => element.name !== '' && element.type !== 'hidden' && element.closest('.field-trap') === null);

const clearErrors = (form) => {
    for (const paragraph of form.querySelectorAll('[data-error-for]')) {
        paragraph.textContent = '';
        paragraph.closest('.field')?.classList.remove('field-invalid');
    }
    for (const element of fieldsOf(form)) {
        element.ariaInvalid = null;
    }
};

const showErrors = (form, errors) => {
    for (const paragraph of form.querySelectorAll('[data-error-for]')) {
        const text = errors[paragraph.dataset.errorFor];
        if (typeof text !== 'string') {
            continue;
        }
        paragraph.textContent = text;
        paragraph.closest('.field')?.classList.add('field-invalid');
        for (const element of fieldsOf(form).filter((element) => element.name === paragraph.dataset.errorFor)) {
            element.ariaInvalid = 'true';
        }
    }
    fieldsOf(form).find((element) => element.ariaInvalid === 'true')?.focus();
};

for (const dialog of document.querySelectorAll('dialog.form-dialog')) {
    const form = dialog.querySelector('form[method="post"]');
    const fields = dialog.querySelector('[data-form-fields]');
    const intro = dialog.querySelector('.form-intro');
    const formError = dialog.querySelector('[data-form-error]');
    const status = dialog.querySelector('[data-form-status]');
    const submit = dialog.querySelector('[data-form-submit]');
    const token = form.querySelector('input[type="hidden"]');
    let sending = false;

    const showSent = (sent) => {
        fields.hidden = sent;
        submit.hidden = sent;
        if (intro !== null) {
            intro.hidden = sent;
        }
    };

    dialog.addEventListener('close', () => {
        if (fields.hidden) {
            showSent(false);
            status.textContent = '';
        }
        openers.get(dialog)?.focus();
        openers.delete(dialog);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (sending) {
            return;
        }
        sending = true;
        submit.disabled = true;
        formError.textContent = '';
        status.textContent = dialog.dataset.sending ?? '';
        clearErrors(form);
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new URLSearchParams(new FormData(form)),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const answer = await response.json();
            status.textContent = '';
            if (answer.ok === true) {
                form.reset();
                // Closed while sending: the next opening shows the empty form, not the thanks.
                if (!dialog.open) {
                    return;
                }
                showSent(true);
                status.textContent = answer.message ?? '';
                status.tabIndex = -1;
                status.focus();
                return;
            }
            if (typeof answer.token === 'string' && token !== null) {
                token.value = answer.token;
            }
            formError.textContent = answer.form ?? '';
            showErrors(form, answer.errors ?? {});
        } catch {
            status.textContent = '';
            formError.textContent = dialog.dataset.networkError ?? '';
        } finally {
            sending = false;
            submit.disabled = false;
        }
    });
}

document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-form-dialog]') : null;
    if (trigger === null || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
    }
    const dialog = document.getElementById(trigger.dataset.formDialog);
    if (!(dialog instanceof HTMLDialogElement) || !dialog.classList.contains('form-dialog') || dialog.open) {
        return;
    }
    event.preventDefault();
    openers.set(dialog, trigger);
    dialog.showModal();
    // The first field, not the close button.
    dialog.querySelector('[data-form-fields] :is(input, select, textarea):not([type="hidden"])')?.focus();
});
