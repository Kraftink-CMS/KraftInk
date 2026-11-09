function initialize(root = document) {
    const forms = [...root.querySelectorAll('[data-market-settings]')];
    if (root.matches?.('[data-market-settings]')) {
        forms.unshift(root);
    }
    for (const form of forms) {
        if (form.dataset.marketInitialized === 'true') {
            continue;
        }
        form.dataset.marketInitialized = 'true';
        const key = form.querySelector('[data-market-site-key]');
        const editor = form.matches('[data-market-key-editor]') ? form : null;
        const fields = form.querySelector('[data-market-key-fields]');
        const confirmation = form.querySelector('[data-sensitive-confirmation]');
        let editing = !editor || editor.classList.contains('show');
        const update = () => {
            fields.disabled = !editing;
            if (!editing) {
                key.value = '';
            }
            confirmation.disabled = !editing || !key.value;
        };
        key.addEventListener('input', update);
        editor?.addEventListener('show.bs.collapse', () => {
            editing = true;
            update();
        });
        editor?.addEventListener('shown.bs.collapse', () => key.focus());
        editor?.addEventListener('hide.bs.collapse', () => {
            editing = false;
            update();
        });
        update();
    }
}

document.addEventListener('DOMContentLoaded', () => initialize());
document.addEventListener('kraft:content-added', (event) => initialize(event.target));
initialize();
