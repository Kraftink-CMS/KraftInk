import Modal from 'bootstrap/js/dist/modal';

const entryPaths = ['kraft-auth-login', 'kraft-auth-register']
    .map((name) => document.querySelector('meta[name="' + name + '"]')?.content)
    .filter(Boolean)
    .map((value) => new URL(value).pathname);
const stepPaths = new Set([
    ...entryPaths,
    ...Array.from(document.querySelectorAll('meta[name="kraft-auth-step"]'), (element) => new URL(element.content).pathname),
]);
const resetTemplate = document.querySelector('meta[name="kraft-auth-reset"]')?.content;
const resetPath = resetTemplate ? new URL(resetTemplate).pathname.replace(/__token__$/, '') : null;
let loading;

function sameSiteUrl(value) {
    try {
        const url = new URL(value, window.location.href);
        return url.origin === window.location.origin && !url.username && !url.password ? url : null;
    } catch {
        return null;
    }
}

function isAuthStep(url) {
    if (!url) {
        return false;
    }
    if (stepPaths.has(url.pathname)) {
        return true;
    }
    const token = resetPath && url.pathname.startsWith(resetPath) ? url.pathname.slice(resetPath.length) : '';
    return /^[A-Za-z0-9_-]+$/.test(token);
}

function restoreFocus(opener) {
    if (!opener?.isConnected) {
        return;
    }
    opener.focus({ preventScroll: true });
    if (document.activeElement === opener) {
        return;
    }
    for (let container = opener.parentElement; container; container = container.parentElement) {
        if (!container.id) {
            continue;
        }
        for (const toggle of document.querySelectorAll(`[aria-controls~="${CSS.escape(container.id)}"]`)) {
            if (toggle.getClientRects().length) {
                toggle.focus({ preventScroll: true });
                if (document.activeElement === toggle) {
                    return;
                }
            }
        }
    }
}

function dismiss(dialog) {
    const modal = Modal.getInstance(dialog);
    if (!modal || !dialog.classList.contains('show')) {
        return Promise.resolve(true);
    }
    return new Promise((resolve) => {
        const hidden = () => resolve(true);
        dialog.addEventListener('hidden.bs.modal', hidden, { once: true });
        modal.hide();
        if (dialog.classList.contains('show')) {
            dialog.removeEventListener('hidden.bs.modal', hidden);
            resolve(false);
        }
    });
}

async function showStep(url, current, opener) {
    loading?.abort();
    const request = new AbortController();
    loading = request;
    let destination = url;
    try {
        const response = await fetch(url.href, {
            headers: { Accept: 'text/html', 'X-Kraft-Auth-Modal': 'true' },
            cache: 'no-store',
            signal: request.signal,
        });
        destination = sameSiteUrl(response.url) ?? url;
        if (request.signal.aborted || (current && !current.isConnected)) {
            return;
        }
        const html = new DOMParser().parseFromString(await response.text(), 'text/html');
        if (request.signal.aborted || (current && !current.isConnected)) {
            return;
        }
        const fragment = html.querySelector('[data-auth-dialog]');
        if (!response.ok || !isAuthStep(destination) || !fragment) {
            window.location.assign(destination.href);
            return;
        }
        const active = document.querySelector('[data-auth-dialog]');
        if (active && !(await dismiss(active))) {
            return;
        }
        if (request.signal.aborted) {
            return;
        }
        const dialog = document.importNode(fragment, true);
        dialog.authDialogOpener = opener;
        const csrf = document.querySelector('meta[name="csrf-token"]');
        if (csrf && dialog.dataset.csrfToken) {
            csrf.content = dialog.dataset.csrfToken;
        }
        document.body.append(dialog);
        const modal = Modal.getOrCreateInstance(dialog);
        dialog.addEventListener('hidden.bs.modal', () => {
            modal.dispose();
            dialog.remove();
            restoreFocus(opener);
        }, { once: true });
        dialog.addEventListener('shown.bs.modal', () => {
            const field = Array.from(dialog.querySelectorAll('input[autocomplete]')).find((input) => !input.closest('[hidden]'));
            field?.focus();
        }, { once: true });
        dialog.dispatchEvent(new CustomEvent('kraft:content-added', { bubbles: true }));
        modal.show();
    } catch (error) {
        if (error.name !== 'AbortError' && (!current || current.isConnected)) {
            window.location.assign(destination.href);
        }
    } finally {
        if (loading === request) {
            loading = null;
        }
    }
}

export function continueAuthDialog(source, value) {
    const dialog = source.closest('[data-auth-dialog]');
    const url = sameSiteUrl(value);
    if (!dialog?.isConnected || !isAuthStep(url)) {
        return false;
    }
    void showStep(url, dialog, dialog.authDialogOpener);
    return true;
}

document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[href]');
    if (
        !link || event.defaultPrevented || event.button !== 0 ||
        event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ||
        (link.target && link.target !== '_self') || link.hasAttribute('download')
    ) {
        return;
    }
    if (continueAuthDialog(link, link.href)) {
        event.preventDefault();
        return;
    }
    const url = sameSiteUrl(link.href);
    if (!url || !entryPaths.includes(url.pathname) || isAuthStep(sameSiteUrl(window.location.href))) {
        return;
    }
    event.preventDefault();
    void showStep(url, null, link);
});

document.addEventListener('ajax:success', (event) => {
    const destination = event.detail?.data?.redirect_url;
    if (
        event.target.dataset.redirectUrl === 'response' && typeof destination === 'string' &&
        continueAuthDialog(event.target, destination)
    ) {
        event.preventDefault();
    }
});
