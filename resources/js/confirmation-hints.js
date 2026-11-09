const hints = new WeakMap();

function updateForm(form) {
    if (!form || form.getAttribute('aria-busy') === 'true') {
        return;
    }
    const protectedAction = [...form.querySelectorAll('[data-sensitive-confirmation]')].some(
        (marker) => !marker.disabled && !marker.closest('fieldset:disabled, [hidden]'),
    );
    const label = JSON.parse(document.querySelector('#kraft-form-config')?.textContent || '{}').confirmation?.hint;
    for (const button of form.elements) {
        if (!['submit', 'image'].includes(button.type)) {
            continue;
        }
        let hint = hints.get(button);
        if (!protectedAction || !label) {
            hint?.remove();
            hints.delete(button);
            continue;
        }
        if (hint?.isConnected) {
            continue;
        }
        hint = document.createElement('span');
        hint.className = 'ms-2 opacity-75';
        hint.title = label;
        hint.setAttribute('role', 'img');
        hint.setAttribute('aria-label', label);
        hint.dataset.sensitiveHint = '';
        const icon = document.createElement('i');
        icon.className = 'bi bi-lock';
        icon.setAttribute('aria-hidden', 'true');
        hint.append(icon);
        button.tagName === 'BUTTON' ? button.append(hint) : button.after(hint);
        hints.set(button, hint);
    }
}

function initialize(root = document) {
    const forms = [...root.querySelectorAll('form')];
    if (root.matches?.('form')) {
        forms.unshift(root);
    }
    forms.forEach(updateForm);
}

document.addEventListener('DOMContentLoaded', () => initialize());
document.addEventListener('kraft:content-added', (event) => queueMicrotask(() => initialize(event.target)));
for (const name of ['input', 'change', 'ajax:complete']) {
    document.addEventListener(name, (event) => {
        const form = event.target.tagName === 'FORM' ? event.target : event.target.form;
        queueMicrotask(() => updateForm(form));
    });
}
initialize();
