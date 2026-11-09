export function initializePagination(document, browser) {
    const pending = new WeakMap();
    const dirtyForms = new WeakSet();
    const drafts = new Map();
    const initialUrl = new URL(browser.location.href);
    const initialPath = initialUrl.pathname;
    const renderedAt = new WeakMap();
    document.querySelectorAll?.('[data-ajax-pagination]').forEach((region) => renderedAt.set(region, initialUrl));
    const mergeChanges = (url, baseline) => {
        const result = new URL(browser.location.href);
        for (const key of new Set([...url.searchParams.keys(), ...baseline.searchParams.keys()])) {
            if (JSON.stringify(url.searchParams.getAll(key)) !== JSON.stringify(baseline.searchParams.getAll(key))) {
                result.searchParams.delete(key);
                url.searchParams.getAll(key).forEach((value) => result.searchParams.append(key, value));
            }
        }
        result.hash = url.hash;
        return result;
    };
    const labels = JSON.parse(document.querySelector?.('#kraft-form-config')?.textContent || '{}').pagination || {};
    const related = (root, id) =>
        [...(root.querySelectorAll?.('[data-ajax-pagination-related]') || [])].filter(
            (element) => element.dataset['ajaxPaginationRelated'] === id,
        );
    const formKey = (form) => form.id || `${form.method}:${form.getAttribute('action') || browser.location.pathname}`;

    for (const name of ['input', 'change', 'editor:change']) {
        document.addEventListener(name, (event) => {
            const form = event.target.form || event.target.closest?.('form');
            if (form && form.method.toLowerCase() !== 'get') {
                dirtyForms.add(form);
            }
        });
    }
    for (const name of ['ajax:success', 'reset']) {
        document.addEventListener(name, (event) => {
            dirtyForms.delete(event.target);
            for (const cache of drafts.values()) {
                for (const [key, form] of cache.forms) {
                    if (form === event.target) {
                        cache.forms.delete(key);
                    }
                }
                for (const [key, modal] of cache.modals) {
                    if (
                        modal.contains(event.target) &&
                        ![...modal.querySelectorAll('form')].some((form) => dirtyForms.has(form))
                    ) {
                        cache.modals.delete(key);
                        if (modal.parentElement === document.body && !modal.classList.contains('show')) {
                            modal.remove();
                        }
                    }
                }
            }
        });
    }

    const preserveDrafts = (id, previous, replacements) => {
        const cache = drafts.get(id) || { forms: new Map(), modals: new Map() };
        drafts.set(id, cache);
        for (const root of previous) {
            root.querySelectorAll('form').forEach((form) => {
                if (!dirtyForms.has(form)) {
                    return;
                }
                const modal = form.closest('.modal');
                if (modal && root.contains(modal) && modal.id) {
                    cache.modals.set(modal.id, modal);
                } else {
                    cache.forms.set(formKey(form), form);
                }
            });
        }
        for (const root of replacements) {
            root.querySelectorAll('.modal[id]').forEach((modal) => {
                const draft = cache.modals.get(modal.id);
                if (draft && draft !== modal) {
                    modal.replaceWith(draft);
                }
            });
            root.querySelectorAll('form').forEach((form) => {
                const draft = cache.forms.get(formKey(form));
                if (draft && draft !== form) {
                    form.replaceWith(draft);
                }
            });
        }
        for (const modal of cache.modals.values()) {
            if (previous.some((root) => root.contains(modal))) {
                document.body.append(modal);
            }
        }
    };

    const showError = (region, url) => {
        let alert = region.querySelector('[data-pagination-error]');
        if (!alert) {
            alert = document.createElement('div');
            alert.dataset.paginationError = '';
            alert.className = 'alert alert-danger d-flex align-items-center justify-content-between gap-3';
            alert.setAttribute('role', 'alert');
            region.prepend(alert);
        }
        const message = document.createElement('span');
        message.textContent = labels.error || '';
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-outline-danger btn-sm';
        retry.textContent = labels.retry || '';
        retry.addEventListener('click', () => void navigate(region, url));
        alert.replaceChildren(message, retry);
    };

    const navigate = async (region, url, push = true, options = {}) => {
        const startedAt = new URL(browser.location.href);
        const searchFields = new Map(
            [...region.querySelectorAll('input')]
                .filter(
                    (field) =>
                        field.id &&
                        ((field.type === 'search' && field.hasAttribute('data-table-parameter')) ||
                            (region.hasAttribute('data-table') &&
                                field.form?.method.toLowerCase() === 'get' &&
                                (field.type === 'search' || ['q', 'search'].includes(field.name)))),
                )
                .map((field) => [field.id, field.value]),
        );
        const previous = [region, ...related(document, region.id)];
        if (previous.some((root) => root.querySelector?.('form[aria-busy="true"]'))) {
            return;
        }
        const preparations = [];
        if (
            !region.dispatchEvent(
                new CustomEvent('pagination:before', {
                    bubbles: true,
                    cancelable: true,
                    detail: { waitUntil: (promise) => preparations.push(Promise.resolve(promise)) },
                }),
            )
        ) {
            if (!push) {
                browser.location.reload();
            }
            return;
        }
        pending.get(region)?.abort();
        const controller = new AbortController();
        pending.set(region, controller);
        region.setAttribute('aria-busy', 'true');
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            if (preparations.length) {
                await Promise.race([
                    Promise.all(preparations),
                    new Promise((resolve, reject) =>
                        controller.signal.addEventListener('abort', () => reject(new Error('pagination_aborted')), {
                            once: true,
                        }),
                    ),
                ]);
            }
            if (pending.get(region) !== controller) {
                return;
            }
            const response = await fetch(url.href, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
                cache: 'no-store',
                signal: controller.signal,
            });
            const destination = new URL(response.url || url.href);
            if (!response.ok || destination.origin !== url.origin || destination.pathname !== url.pathname) {
                throw new Error('pagination_unavailable');
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const replacement = page.getElementById(region.id);
            if (!replacement?.hasAttribute('data-ajax-pagination')) {
                throw new Error('pagination_unavailable');
            }
            if (pending.get(region) !== controller) {
                return;
            }
            const extras = related(page, region.id);
            if (
                extras.length !== previous.length - 1 ||
                extras.some((element, index) => !element.id || element.id !== previous[index + 1].id)
            ) {
                throw new Error('pagination_unavailable');
            }
            const replacements = [replacement, ...extras].map((element) => {
                element.querySelectorAll('script').forEach((script) => script.remove());
                return document.importNode(element, true);
            });
            previous.forEach((element) =>
                element.dispatchEvent(new CustomEvent('pagination:replace', { bubbles: true })),
            );
            previous.forEach((element) =>
                element.querySelectorAll('[data-media-modal]').forEach((modal) => document.body.append(modal)),
            );
            if (document.querySelector?.('[data-media-modal]')) {
                replacements.forEach((element) =>
                    element.querySelectorAll('[data-media-modal]').forEach((modal) => modal.remove()),
                );
            }
            preserveDrafts(region.id, previous, replacements);
            const focused = document.activeElement;
            const focusId = region.hasAttribute('data-table') && region.contains(focused) ? focused?.id : null;
            const selection =
                focusId && typeof focused.selectionStart === 'number'
                    ? [focused.selectionStart, focused.selectionEnd]
                    : null;
            const searchDrafts = [...searchFields].flatMap(([id, value]) => {
                const field = document.getElementById(id);
                return field && region.contains(field) && field.value !== value ? [[id, field.value]] : [];
            });
            previous.forEach((element, index) => element.replaceWith(replacements[index]));
            replacements.forEach((element) => renderedAt.set(element, url));
            const next = replacements[0];
            if (push) {
                browser.history.replaceState(
                    { ...browser.history.state, kraftPagination: region.id },
                    '',
                    browser.location.href,
                );
                browser.history.pushState(
                    { ...browser.history.state, kraftPagination: region.id },
                    '',
                    mergeChanges(url, startedAt).href,
                );
            }
            replacements.forEach((element) => {
                element.dispatchEvent(new CustomEvent('pagination:after', { bubbles: true }));
                element.dispatchEvent(new CustomEvent('kraft:content-added', { bubbles: true }));
            });
            const focusTarget = focusId ? document.getElementById(focusId) : null;
            for (const [id, value] of searchDrafts) {
                const field = document.getElementById(id);
                if (field && field.value !== value) {
                    field.value = value;
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
            (focusTarget || (options.scroll !== false ? next : null))?.focus({ preventScroll: true });
            if (selection && focusTarget?.setSelectionRange) {
                focusTarget.setSelectionRange(...selection);
            }
            if (options.scroll !== false) {
                next.scrollIntoView({ block: 'start' });
            }
        } catch {
            if (pending.get(region) === controller) {
                region.dispatchEvent(new CustomEvent('pagination:error', { bubbles: true }));
                if (labels.error) {
                    showError(region, url);
                } else {
                    browser.location.assign(url.href);
                }
            }
        } finally {
            clearTimeout(timeout);
            if (pending.get(region) === controller) {
                pending.delete(region);
                region.removeAttribute('aria-busy');
            }
        }
    };

    document.addEventListener('pagination:navigate', (event) => {
        const region = event.target;
        if (!region?.id || !region.hasAttribute('data-ajax-pagination') || !event.detail?.url) {
            return;
        }
        const url = new URL(event.detail.url, browser.location.href);
        const current = new URL(browser.location.href);
        if (url.origin !== current.origin || url.pathname !== current.pathname || url.username || url.password) {
            return;
        }
        void navigate(region, url, event.detail.push !== false, { scroll: event.detail.scroll !== false });
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a.page-link');
        const region = link?.closest('[data-ajax-pagination]');
        if (
            !region?.id ||
            event.defaultPrevented ||
            event.button !== 0 ||
            event.ctrlKey ||
            event.metaKey ||
            event.shiftKey ||
            event.altKey ||
            link.hasAttribute('download') ||
            (link.target && link.target !== '_self')
        ) {
            return;
        }
        const url = new URL(link.href, browser.location.href);
        const current = new URL(browser.location.href);
        if (url.origin !== current.origin || url.pathname !== current.pathname || url.username || url.password) {
            return;
        }
        event.preventDefault();
        void navigate(region, mergeChanges(url, renderedAt.get(region) || initialUrl));
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || form.method.toLowerCase() !== 'get') {
            return;
        }
        const region = form.dataset['ajaxPaginationForm']
            ? document.getElementById(form.dataset['ajaxPaginationForm'])
            : form.closest('[data-ajax-pagination]');
        if (!region?.id || !form.reportValidity()) {
            return;
        }
        const url = new URL(form.action || browser.location.href, browser.location.href);
        const current = new URL(browser.location.href);
        if (url.origin !== current.origin || url.pathname !== current.pathname || url.username || url.password) {
            return;
        }
        const data = new FormData(form, event.submitter);
        url.search = current.search;
        new Set([...form.elements].map((field) => field.name).filter(Boolean)).forEach((name) =>
            url.searchParams.delete(name),
        );
        data.forEach((value, name) => url.searchParams.append(name, String(value)));
        const baseline = renderedAt.get(region) || initialUrl;
        const names = region.dataset['pageName']
            ? [region.dataset['pageName']]
            : [...region.querySelectorAll('a.page-link[href]')].flatMap((link) =>
                  [...new URL(link.href).searchParams.keys()].filter(
                      (key) =>
                          (key === 'page' || key.endsWith('_page')) &&
                          new URL(link.href).searchParams.get(key) !== baseline.searchParams.get(key),
                  ),
              );
        names.forEach((name) => url.searchParams.delete(name));
        url.hash = region.id;
        event.preventDefault();
        void navigate(region, url);
    });

    browser.addEventListener('popstate', (event) => {
        if (!event.state?.kraftPagination) {
            return;
        }
        if (new URL(browser.location.href).pathname !== initialPath) {
            browser.location.reload();
            return;
        }
        const region = document.getElementById(event.state.kraftPagination);
        if (region?.hasAttribute('data-ajax-pagination')) {
            void navigate(region, new URL(browser.location.href), false);
        } else {
            browser.location.reload();
        }
    });
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    initializePagination(document, window);
}
