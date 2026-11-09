const initialized = new WeakSet();

function initializeOptionFilters(root = document) {
    const selector = 'select[data-option-filter]';
    const selects = [...root.querySelectorAll(selector)];
    if (root.matches?.(selector)) {
        selects.unshift(root);
    }

    for (const select of selects) {
        const source = select.form?.elements.namedItem(select.dataset.optionFilter);
        if (!source || initialized.has(select)) {
            continue;
        }
        initialized.add(select);
        const update = () => {
            for (const option of select.options) {
                const unavailable = option.value !== '' && option.dataset.optionGroup !== source.value;
                option.hidden = unavailable;
                option.disabled = unavailable;
                if (unavailable && option.selected) {
                    select.value = '';
                }
            }
        };
        source.addEventListener('change', update);
        update();
    }
}

initializeOptionFilters();
document.addEventListener('kraft:content-added', (event) => initializeOptionFilters(event.target));
