const drafts = new WeakMap();
const forms = new Set();
const initializedForms = new WeakSet();

const identifier = () => {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
};

function notify(form) {
    form.dispatchEvent?.(new CustomEvent('media-draft:change', { bubbles: true }));
}

export function stageMedia(form, file, options = {}) {
    if (!form || !file || typeof file.arrayBuffer !== 'function' || typeof file.size !== 'number') {
        throw new Error('local_image_unavailable');
    }
    let records = drafts.get(form);
    if (!records) {
        records = new Map();
        drafts.set(form, records);
        forms.add(form);
    }
    if (!initializedForms.has(form)) {
        initializedForms.add(form);
        form.addEventListener?.('reset', (event) => {
            setTimeout(() => {
                if (!event.defaultPrevented) {
                    resetMediaDraft(form);
                }
            }, 0);
        });
    }
    let changed = false;
    let record = [...records.values()].find(
        (item) =>
            item.source === file ||
            item.asset.file === file ||
            (options.url && item.aliases.has(options.url)) ||
            (options.aliases ?? []).some((url) => item.aliases.has(url)),
    );
    if (!record) {
        const name = options.name || file.name || 'image';
        const upload =
            typeof file.name === 'string' && file.name === name ? file : new File([file], name, { type: file.type });
        const asset = {
            id: identifier(),
            name,
            url: URL.createObjectURL(upload),
            mime: upload.type,
            size: upload.size,
            visibility: options.visibility === 'public' ? 'public' : 'private',
            local: true,
            file: upload,
        };
        record = { asset, source: file, aliases: new Set([asset.url]) };
        records.set(asset.id, record);
        changed = true;
    } else if (options.visibility) {
        const visibility = options.visibility === 'public' ? 'public' : 'private';
        changed = visibility !== record.asset.visibility;
        record.asset.visibility = visibility;
    }
    if (typeof options.url === 'string' && /^(blob:|data:image\/)/i.test(options.url)) {
        record.aliases.add(options.url);
    }
    for (const url of options.aliases ?? []) {
        if (typeof url === 'string' && /^(blob:|data:image\/)/i.test(url)) {
            record.aliases.add(url);
        }
    }
    if (changed) {
        notify(form);
    }
    return record.asset;
}

export function listMediaDraft(form) {
    return [...(drafts.get(form)?.values() ?? [])].map((record) => record.asset);
}

export function getMediaDraft(form, id) {
    return drafts.get(form)?.get(id)?.asset ?? null;
}

export function findMediaDraftByUrl(form, url) {
    return [...(drafts.get(form)?.values() ?? [])].find((record) => record.aliases.has(url))?.asset ?? null;
}

export function releaseMediaDraft(form, id) {
    const records = drafts.get(form);
    const record = records?.get(id);
    if (!record) {
        return;
    }
    URL.revokeObjectURL(record.asset.url);
    records.delete(id);
    if (!records.size) {
        drafts.delete(form);
        forms.delete(form);
    }
    notify(form);
}

export function resetMediaDraft(form) {
    for (const record of drafts.get(form)?.values() ?? []) {
        URL.revokeObjectURL(record.asset.url);
    }
    drafts.delete(form);
    forms.delete(form);
    notify(form);
}

export function hasReferencedMediaDraft(form) {
    const cover = form.elements?.namedItem?.('cover_local_image')?.value;
    if (cover && getMediaDraft(form, cover)) {
        return true;
    }
    for (const field of form.querySelectorAll?.('textarea[data-editor]') ?? []) {
        if (/<img\b[^>]*\bsrc\s*=\s*["']?(?:blob:|data:image\/)/i.test(field.value ?? '')) {
            return true;
        }
    }
    return false;
}

export function mediaDraftForms() {
    return [...forms];
}

export function augmentDraftFormData(form, data) {
    if (!form.hasAttribute?.('data-content-form')) {
        return 0;
    }
    const used = new Map();
    const body = data.get('body');
    if (typeof body === 'string' && /(blob:|data:image\/|data-content-image)/i.test(body)) {
        const template = (form.ownerDocument ?? document).createElement('template');
        template.innerHTML = body;
        for (const image of template.content.querySelectorAll('img')) {
            const source = image.getAttribute('src') ?? '';
            image.removeAttribute('data-content-image');
            if (!/^(blob:|data:image\/)/i.test(source)) {
                if (/(blob:|data:image\/)/i.test(image.getAttribute('srcset') ?? '')) {
                    throw new Error('local_image_unavailable');
                }
                continue;
            }
            const asset = findMediaDraftByUrl(form, source);
            if (!asset?.file) {
                throw new Error('local_image_unavailable');
            }
            used.set(asset.id, asset);
            image.setAttribute('data-content-image', asset.id);
            image.setAttribute('src', '');
            for (const attribute of ['data-mce-src', 'srcset', 'sizes']) {
                image.removeAttribute(attribute);
            }
        }
        data.set('body', template.innerHTML);
    }
    const coverId = data.get('cover_local_image');
    if (typeof coverId === 'string' && coverId !== '') {
        const cover = getMediaDraft(form, coverId);
        if (!cover?.file) {
            throw new Error('local_image_unavailable');
        }
        used.set(cover.id, cover);
    }
    for (const key of [...data.keys()]) {
        if (key === 'content_images' || key.startsWith('content_images[')) {
            data.delete(key);
        }
    }
    const nativeFiles = [...data.values()].filter(
        (value) => value && typeof value === 'object' && typeof value.arrayBuffer === 'function' && value.size > 0,
    ).length;
    const limit = Number(form.dataset.contentImageLimit) || 20;
    if (used.size + nativeFiles > limit) {
        throw new Error('local_image_limit');
    }
    for (const asset of used.values()) {
        data.append('content_images[' + asset.id + ']', asset.file, asset.name);
    }
    return used.size;
}
