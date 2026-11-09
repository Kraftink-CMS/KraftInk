export function initContentCovers(root = document) {
    root.addEventListener('media:change', (event) => {
        const container = event.target.closest('[data-content-cover]');
        if (!container) {
            return;
        }
        const { asset, value } = event.detail;
        const visibility = asset?.visibility === 'public' ? 'public' : 'private';
        const local = asset?.local ? asset.id : '';
        container.querySelector('[data-cover-visibility]').value = visibility;
        container.querySelector('[data-cover-local]').value = local;
        container.querySelector('[data-cover-private]').value = !local && visibility === 'private' ? value : '';
        container.querySelector('[data-cover-public]').value = !local && visibility === 'public' ? value : '';
        container.querySelector('[data-cover-visibility]').dispatchEvent(new Event('change', { bubbles: true }));
    });
    root.addEventListener('ajax:error', (event) => {
        for (const container of event.target.querySelectorAll('[data-content-cover]')) {
            const invalid = container.querySelector(
                '[data-cover-private][aria-invalid="true"], [data-cover-public][aria-invalid="true"]',
            );
            if (!invalid) {
                continue;
            }
            const button = container.querySelector('[data-media-open]');
            button.classList.add('is-invalid');
            button.setAttribute('aria-invalid', 'true');
            button.setAttribute('aria-describedby', invalid.getAttribute('aria-describedby') ?? '');
        }
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initContentCovers());
    } else {
        initContentCovers();
    }
}
