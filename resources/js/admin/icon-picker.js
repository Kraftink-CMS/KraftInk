const namePattern = /^[a-z0-9][a-z0-9-]{0,59}$/;
const pageSize = 24;
const pickerSelector = '[data-icon-picker]';
const inputSelector = '[data-icon-picker-input]';
let catalog;

function icons(document) {
    if (!catalog) {
        const data = document.querySelector('[data-icon-catalog]');
        if (data) {
            const { theme, entries, bootstrap } = JSON.parse(data.textContent);
            const themeEntries = Object.entries(entries);
            catalog = {
                theme,
                entries: new Map(themeEntries),
                bootstrap: new Set(bootstrap),
                choices: (theme ? themeEntries : bootstrap.map((key) => [key, { name: key }]))
                    .sort(([, first], [, second]) => first.name.localeCompare(second.name)),
            };
        }
    }
    return catalog;
}

export function createContentIcon(input, name, className = '') {
    if (!namePattern.test(name ?? '')) {
        return null;
    }
    const document = input.ownerDocument;
    const library = icons(document);
    const entry = library?.entries.get(name);
    if (!entry && library?.bootstrap.size && !library.bootstrap.has(name)) {
        return null;
    }

    const icon = document.createElement(entry ? 'span' : 'i');
    icon.className = `${entry ? 'content-icon' : `bi bi-${name}`} ${className}`.trim();
    icon.setAttribute('aria-hidden', 'true');
    if (entry) {
        icon.style.setProperty('--content-icon', `url("${entry.url}")`);
    }
    return icon;
}

function updatePreview(input) {
    const picker = input.closest(pickerSelector);
    const preview = picker.querySelector('[data-icon-picker-preview]');
    const status = picker.querySelector('[data-icon-picker-status]');
    const icon = createContentIcon(input, input.value);
    preview.replaceChildren(...(icon ? [icon] : []));
    status.textContent = '';
    if (input.value && !icon) {
        status.textContent = status.dataset.unavailable;
    } else if (icon?.classList.contains('bi') && icons(input.ownerDocument)?.theme) {
        status.textContent = status.dataset.fallback;
    }
}

function showChoices(picker, append = false) {
    const input = picker.querySelector(inputSelector);
    const search = picker.querySelector('[data-icon-picker-search]');
    const list = picker.querySelector('[data-icon-picker-results]');
    const more = picker.querySelector('[data-icon-picker-more]');
    const query = search.value.toLowerCase().trim();
    const choices = (icons(picker.ownerDocument)?.choices ?? []).filter(
        ([key, { name }]) => key.includes(query) || name.toLowerCase().includes(query),
    );
    if (!append) {
        list.replaceChildren();
        list.scrollTop = 0;
    }
    const offset = list.children.length;
    const document = picker.ownerDocument;
    for (const [key, { name }] of choices.slice(offset, offset + pageSize)) {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-secondary btn-sm w-100 text-start text-break';
        button.dataset.iconPickerChoice = key;
        const icon = createContentIcon(input, key, 'me-2');
        if (icon) {
            button.append(icon);
        }
        button.append(name);
        item.append(button);
        list.append(item);
    }
    picker.querySelector('[data-icon-picker-empty]').hidden = choices.length > 0;
    more.hidden = list.children.length >= choices.length;
    if (append) {
        list.children[offset]?.firstElementChild?.focus();
    }
}

function showPanel(picker, visible) {
    const toggle = picker.querySelector('[data-icon-picker-toggle]');
    const panel = picker.querySelector('[data-icon-picker-panel]');
    toggle.setAttribute('aria-expanded', String(visible));
    panel.hidden = !visible;
    if (visible) {
        const search = picker.querySelector('[data-icon-picker-search]');
        search.value = '';
        showChoices(picker);
        search.focus();
    }
}

function initIconPicker(document) {
    document.addEventListener('click', (event) => {
        const picker = event.target.closest(pickerSelector);
        if (!picker) {
            return;
        }
        const input = picker.querySelector(inputSelector);
        if (input.matches(':disabled, [readonly]')) {
            return;
        }
        const toggle = event.target.closest('[data-icon-picker-toggle]');
        const choice = event.target.closest('[data-icon-picker-choice]');
        if (toggle) {
            showPanel(picker, toggle.getAttribute('aria-expanded') !== 'true');
        } else if (choice) {
            input.value = choice.dataset.iconPickerChoice;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            showPanel(picker, false);
            input.focus();
        } else if (event.target.closest('[data-icon-picker-more]')) {
            showChoices(picker, true);
        }
    });

    document.addEventListener('input', (event) => {
        if (event.target.matches(inputSelector)) {
            updatePreview(event.target);
        } else if (event.target.matches('[data-icon-picker-search]')) {
            showChoices(event.target.closest(pickerSelector));
        }
    });

    document.addEventListener('change', (event) => {
        if (event.target.matches(inputSelector)) {
            updatePreview(event.target);
        }
    });

    document.addEventListener('keydown', (event) => {
        const picker = event.target.closest(pickerSelector);
        if (!picker || picker.querySelector('[data-icon-picker-panel]').hidden) {
            return;
        }
        if (event.key === 'Escape') {
            event.stopPropagation();
            event.preventDefault();
            showPanel(picker, false);
            picker.querySelector('[data-icon-picker-toggle]').focus();
        } else if (event.key === 'Enter' && event.target.matches('[data-icon-picker-search]')) {
            event.preventDefault();
            picker.querySelector('[data-icon-picker-choice]')?.click();
        }
    }, true);

    document.addEventListener('reset', (event) => {
        setTimeout(() => event.target.querySelectorAll(inputSelector).forEach(updatePreview), 0);
    });
}

if (typeof document !== 'undefined') {
    initIconPicker(document);
}
