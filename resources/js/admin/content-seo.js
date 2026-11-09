const initializedPreviews = new WeakSet();

export function initContentSeoPreview(root = document) {
    root.querySelectorAll('[data-content-seo-preview]').forEach((preview) => {
        if (initializedPreviews.has(preview)) {
            return;
        }
        const form = /** @type {HTMLFormElement|null} */ (preview.closest('[data-content-form]'));
        if (!form) {
            return;
        }
        initializedPreviews.add(preview);
        const status = preview.querySelector('[data-content-seo-status]');
        const result = preview.querySelector('[data-content-seo-result]');
        const image = preview.querySelector('[data-content-seo-image]');
        const models = preview.querySelector('[data-content-seo-models]');
        let timer;
        let request;
        let generation = 0;
        const update = async () => {
            const currentGeneration = ++generation;
            request?.abort();
            const title = form.elements.namedItem('title')?.value.trim() ?? '';
            if (!title) {
                result.hidden = true;
                status.textContent = preview.dataset.empty;
                status.hidden = false;
                return;
            }
            request = new AbortController();
            status.textContent = preview.dataset.loading;
            status.hidden = false;
            result.hidden = true;
            /** @type {Record<string, string|undefined>} */
            const body = { kind: form.dataset['contentKind'] };
            for (const field of ['title', 'summary', 'body', 'slug', 'locale', 'cover_media_id', 'cover_visibility']) {
                const control = form.elements.namedItem(field);
                if (control) {
                    body[field] = control.matches?.(':disabled') ? '' : control.value;
                }
            }
            if (body.cover_visibility === 'private') {
                body.cover_media_id = '';
            }
            if (models) {
                const address = new URL(models.href);
                address.searchParams.set('target', body.kind === 'page' ? 'site.pages.show' : 'site.news.show');
                models.href = address.href;
            }
            try {
                const response = await fetch(preview.dataset['previewUrl'], {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.elements.namedItem('_token')?.value ?? '',
                    },
                    body: JSON.stringify(body),
                    signal: request.signal,
                });
                if (!response.ok) {
                    throw new Error();
                }
                const envelope = await response.json();
                if (envelope.success !== true || !envelope.data) {
                    throw new Error();
                }
                const metadata = envelope.data;
                if (currentGeneration !== generation) {
                    return;
                }
                preview.querySelector('[data-content-seo-title]').textContent = metadata.title;
                preview.querySelector('[data-content-seo-description]').textContent = metadata.description;
                preview.querySelector('[data-content-seo-url]').textContent = metadata.canonical;
                image.hidden = !metadata.image;
                if (metadata.image) {
                    image.src = metadata.image;
                } else {
                    image.removeAttribute('src');
                }
                result.hidden = false;
                status.hidden = true;
            } catch (error) {
                if (error.name === 'AbortError' || currentGeneration !== generation) {
                    return;
                }
                result.hidden = true;
                status.textContent = preview.dataset.error;
                status.hidden = false;
            }
        };
        const schedule = (event) => {
            if (
                event &&
                !['title', 'summary', 'body', 'slug', 'kind', 'locale', 'cover_media_id', 'cover_visibility'].includes(
                    event.target.name,
                ) &&
                event.type !== 'media:change'
            ) {
                return;
            }
            clearTimeout(timer);
            request?.abort();
            generation++;
            result.hidden = true;
            status.textContent = preview.dataset.loading;
            status.hidden = false;
            timer = setTimeout(update, 300);
        };
        for (const event of ['input', 'change', 'editor:change', 'media:change']) {
            form.addEventListener(event, schedule);
        }
        void update();
    });
}

if (typeof document !== 'undefined') {
    initContentSeoPreview();
    document.addEventListener('kraft:content-added', (event) => initContentSeoPreview(event.target));
}
