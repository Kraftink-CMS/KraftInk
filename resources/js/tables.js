export function tableDestination(current, pageName, values, changes = {}, resetPage = true) {
    const url = new URL(current);
    if (resetPage) {
        url.searchParams.delete(pageName);
    }
    const fields = new Map();
    for (const [name, value] of values) {
        if (name === pageName && resetPage) {
            continue;
        }
        if (!fields.has(name)) {
            fields.set(name, []);
        }
        if (value !== '' && value !== null) {
            fields.get(name).push(String(value));
        }
    }
    for (const [name, entries] of fields) {
        url.searchParams.delete(name);
        entries.forEach((value) => url.searchParams.append(name, value));
    }
    for (const [name, value] of Object.entries(changes)) {
        if (value === '' || value === null) {
            url.searchParams.delete(name);
        } else {
            url.searchParams.set(name, String(value));
        }
    }
    return url;
}

export function initializeTables(document, browser) {
    const initialized = new WeakSet();
    const timers = new WeakMap();
    const labels = JSON.parse(document.querySelector('#kraft-form-config')?.textContent || '{}').tables || {};
    const owner = (element) => element?.closest('[data-table]');
    const regionFor = (element) => {
        const form = element?.tagName === 'FORM' ? element : element?.form;
        return owner(element) || document.getElementById(form?.dataset.ajaxPaginationForm || '');
    };
    const samePage = (form) => {
        const action = new URL(form.action || browser.location.href, browser.location.href);
        const current = new URL(browser.location.href);
        return (
            action.origin === current.origin &&
            action.pathname === current.pathname &&
            !action.username &&
            !action.password
        );
    };
    const controls = (region) =>
        [...(region?.querySelectorAll('[data-table-controls]') || [])].find((item) => owner(item) === region);
    const destination = (region, changes = {}, resetPage = true) => {
        const panel = controls(region);
        if (!panel) {
            return null;
        }
        const values = [...region.querySelectorAll('[data-table-parameter]')]
            .filter((field) => owner(field) === region)
            .map((field) => [field.dataset.tableParameter, field.value]);
        if (panel.dataset.tableCurrentSort !== undefined) {
            values.push([panel.dataset.pageName + '_sort', panel.dataset.tableCurrentSort]);
        }
        if (panel.dataset.tableDirection !== undefined) {
            values.push([panel.dataset.pageName + '_direction', panel.dataset.tableDirection]);
        }
        const forms = new Set([
            ...region.querySelectorAll('form'),
            ...document.querySelectorAll('form[data-ajax-pagination-form]'),
        ]);
        for (const form of forms) {
            if (
                form === panel ||
                form.hasAttribute('data-table-filters') ||
                form.hasAttribute('data-table-page-size') ||
                form.method.toLowerCase() !== 'get' ||
                (owner(form) !== region && form.dataset.ajaxPaginationForm !== region.id)
            ) {
                continue;
            }
            if (!samePage(form)) {
                continue;
            }
            for (const name of new Set([...form.elements].map((field) => field.name).filter(Boolean))) {
                values.push([name, null]);
            }
            for (const [name, value] of new FormData(form)) {
                values.push([name, String(value)]);
            }
        }
        const url = tableDestination(browser.location.href, panel.dataset.pageName, values, changes, resetPage);
        url.hash = region.id;
        return url;
    };
    const navigate = (region, changes = {}, push = true, resetPage = true) => {
        const url = destination(region, changes, resetPage);
        if (!url) {
            return;
        }
        clearTimeout(timers.get(region));
        region.dispatchEvent(
            new CustomEvent('pagination:navigate', {
                bubbles: true,
                detail: { url: url.href, push, scroll: false },
            }),
        );
    };
    const init = (root = document) => {
        const regions = [...root.querySelectorAll('[data-table]')];
        if (root.matches?.('[data-table]')) {
            regions.unshift(root);
        }
        for (const region of regions) {
            if (initialized.has(region) || !region.id || !region.hasAttribute('data-ajax-pagination')) {
                continue;
            }
            const panel = controls(region);
            if (!panel) {
                continue;
            }
            initialized.add(region);
            region.classList.add('kraft-table');
            region.dataset.pageName = panel.dataset.pageName;
            let sorts;
            try {
                sorts = JSON.parse(panel.dataset.tableSorts || '[]');
            } catch {
                sorts = [];
            }
            for (const heading of region.querySelectorAll('th[data-table-sort]')) {
                const key = heading.dataset.tableSort;
                if (owner(heading) !== region || !sorts.includes(key)) {
                    continue;
                }
                const active = panel.dataset.tableCurrentSort === key;
                const direction = active && panel.dataset.tableDirection === 'asc' ? 'desc' : 'asc';
                heading.setAttribute('scope', 'col');
                heading.setAttribute(
                    'aria-sort',
                    active ? (panel.dataset.tableDirection === 'desc' ? 'descending' : 'ascending') : 'none',
                );
                const link = document.createElement('a');
                link.id = region.id + '-sort-' + key;
                link.className = 'kraft-table-sort';
                link.dataset.tableOrder = key;
                link.dataset.tableDirection = direction;
                link.setAttribute(
                    'aria-label',
                    (labels[direction === 'asc' ? 'sort_ascending' : 'sort_descending'] || ':column').replace(
                        ':column',
                        heading.textContent.trim(),
                    ),
                );
                link.href = destination(region, {
                    [panel.dataset.pageName + '_sort']: key,
                    [panel.dataset.pageName + '_direction']: direction,
                }).href;
                link.append(...heading.childNodes);
                heading.append(link);
            }
            region.dispatchEvent(new CustomEvent('tables:ready', { bubbles: true }));
        }
    };

    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-table-order]');
        const region = owner(link);
        if (
            region &&
            event.button === 0 &&
            !event.ctrlKey &&
            !event.metaKey &&
            !event.shiftKey &&
            !event.altKey &&
            !event.defaultPrevented
        ) {
            const panel = controls(region);
            event.preventDefault();
            navigate(region, {
                [panel.dataset.pageName + '_sort']: link.dataset.tableOrder,
                [panel.dataset.pageName + '_direction']: link.dataset.tableDirection,
            });
        }
    });
    document.addEventListener('input', (event) => {
        const field = event.target;
        const region = regionFor(field);
        const commonSearch = field.type === 'search' && field.hasAttribute('data-table-parameter');
        const filterSearch =
            field.form?.method.toLowerCase() === 'get' &&
            samePage(field.form) &&
            ['INPUT', 'TEXTAREA'].includes(field.tagName) &&
            ['text', 'search', 'number', 'email', 'url', 'tel', 'textarea'].includes(field.type || 'text');
        if (!region?.hasAttribute('data-table') || (!commonSearch && !filterSearch) || event.isComposing) {
            return;
        }
        clearTimeout(timers.get(region));
        timers.set(
            region,
            setTimeout(() => {
                if (region.isConnected) {
                    if (commonSearch) {
                        navigate(region);
                    } else {
                        field.form.requestSubmit();
                    }
                }
            }, 350),
        );
    });
    document.addEventListener('change', (event) => {
        const field = event.target;
        const region = regionFor(field);
        if (!region?.hasAttribute('data-table')) {
            return;
        }
        if (field.hasAttribute('data-table-parameter') && field.tagName === 'SELECT') {
            navigate(region);
        } else if (
            field.form?.method.toLowerCase() === 'get' &&
            field.name &&
            !['hidden', 'submit', 'button'].includes(field.type) &&
            samePage(field.form)
        ) {
            field.form.requestSubmit();
        }
    });
    document.addEventListener('keydown', (event) => {
        const field = event.target;
        const panel =
            field.closest('[data-table-filters]') ||
            field.closest('[data-table-page-size]') ||
            field.closest('[data-table-controls]');
        if (event.key === 'Enter' && panel && panel.tagName !== 'FORM' && !event.isComposing) {
            event.preventDefault();
            navigate(owner(panel));
        }
    });
    document.addEventListener(
        'submit',
        (event) => {
            const form = event.target;
            const region = regionFor(form);
            if (!region?.hasAttribute('data-table') || form.method.toLowerCase() !== 'get' || event.defaultPrevented) {
                return;
            }
            if (!samePage(form) || !form.reportValidity()) {
                return;
            }
            event.preventDefault();
            navigate(region);
        },
        true,
    );
    document.addEventListener('kraft:content-added', (event) => init(event.target));
    init();

    return {
        init,
        reload: (element) => navigate(element?.matches?.('[data-table]') ? element : owner(element), {}, false, false),
    };
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    window.KraftTables = initializeTables(document, window);
}
