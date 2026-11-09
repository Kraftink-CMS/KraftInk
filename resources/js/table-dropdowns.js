import Dropdown from 'bootstrap/js/dist/dropdown';

const selector = 'table [data-bs-toggle="dropdown"]';

function toggles(root) {
    const elements = [...root.querySelectorAll(selector)];
    if (root.matches?.(selector)) {
        elements.unshift(root);
    }
    return elements;
}

function initialize(root) {
    for (const toggle of toggles(root)) {
        Dropdown.getOrCreateInstance(toggle, {
            popperConfig: (config) => ({ ...config, strategy: 'fixed' }),
        });
    }
}

document.addEventListener('kraft:content-added', (event) => initialize(event.target));
document.addEventListener('pagination:replace', (event) => {
    for (const toggle of toggles(event.target)) {
        const dropdown = Dropdown.getInstance(toggle);
        dropdown?.hide();
        dropdown?.dispose();
    }
});

initialize(document);
