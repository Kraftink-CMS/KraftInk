import Modal from 'bootstrap/js/dist/modal';
import Tab from 'bootstrap/js/dist/tab';
import { stageMedia, listMediaDraft, getMediaDraft } from '../media-drafts.js';

export function mediaAddress(value, base, image = false) {
    if (typeof value !== 'string' || !value.trim()) {
        return null;
    }
    try {
        const url = new URL(value, base);
        const origin = new URL(base).origin;
        if (url.username || url.password || !['https:', 'http:'].includes(url.protocol)) {
            return null;
        }
        return url.origin === origin || (image && url.protocol === 'https:') ? url.href : null;
    } catch {
        return null;
    }
}

export function mediaIdentifier(value, type = 'integer') {
    return type === 'uuid'
        ? typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value)
        : Number.isSafeInteger(Number(value)) && Number(value) > 0;
}

export function createMediaRequests(fetcher, base) {
    let controller;
    let generation = 0;
    const cancel = () => {
        generation++;
        controller?.abort();
    };
    return {
        cancel,
        async load(endpoint, parameters) {
            cancel();
            const ticket = generation;
            const address = mediaAddress(endpoint, base);
            if (!address) {
                throw new Error();
            }
            const url = new URL(address);
            for (const [key, value] of parameters) {
                url.searchParams.append(key, value);
            }
            controller = new AbortController();
            try {
                const response = await fetcher(url.href, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                    redirect: 'error',
                });
                const envelope = await response.json();
                const data = envelope.data;
                if (generation !== ticket) {
                    return null;
                }
                if (!response.ok || envelope.success !== true || !Array.isArray(data?.items) || !data.pagination) {
                    throw new Error();
                }
                return data;
            } catch (error) {
                if (generation !== ticket || error.name === 'AbortError') {
                    return null;
                }
                throw error;
            }
        },
    };
}

export function clearMediaPickerErrors(picker) {
    const feedback = Array.from(picker.querySelectorAll('.invalid-feedback'));
    const references = new Set(feedback.map((item) => item.id).filter(Boolean));
    for (const field of picker.querySelectorAll('[aria-invalid], [aria-describedby], .is-invalid')) {
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        const description = field.getAttribute('aria-describedby');
        if (description !== null) {
            const remaining = description.split(/\s+/).filter((value) => value && !references.has(value));
            remaining.length
                ? field.setAttribute('aria-describedby', remaining.join(' '))
                : field.removeAttribute('aria-describedby');
        }
    }
    feedback.forEach((item) => item.remove());
}

/**
 * @param {Document|HTMLElement} [root]
 * @param {{Modal?: typeof Modal, Tab?: typeof Tab, fetch?: typeof fetch,
 * drafts?: Pick<typeof import('../media-drafts.js'), 'stageMedia'|'listMediaDraft'|'getMediaDraft'>}} [dependencies]
 */
export function initMediaPickers(root = document, dependencies = {}) {
    const modalElement = /** @type {HTMLElement|null} */ (root.querySelector('[data-media-modal]'));
    if (!modalElement || modalElement.dataset.mediaReady === 'true') {
        return;
    }
    modalElement.dataset.mediaReady = 'true';
    const ModalClass = dependencies.Modal ?? Modal;
    const TabClass = dependencies.Tab ?? Tab;
    const fetcher = dependencies.fetch ?? window.fetch.bind(window);
    const base = window.location.href;
    /** @type {Record<string, string>} */
    const labels = JSON.parse(modalElement.dataset.mediaLabels);
    const modal = ModalClass.getOrCreateInstance(modalElement);
    const requests = createMediaRequests(fetcher, base);
    const find = (selector) => modalElement.querySelector(selector);
    const search = find('[data-media-query]');
    const results = find('[data-media-results]');
    const draftResults = find('[data-media-draft-results]');
    const draftSection = find('[data-media-draft-section]');
    const status = find('[data-media-status]');
    const error = find('[data-media-error]');
    const previous = find('[data-media-previous]');
    const next = find('[data-media-next]');
    const select = find('[data-media-select]');
    const pendingLabel = find('[data-media-pending]');
    const upload = find('[data-media-upload]');
    const uploadFile = find('[data-media-upload-file]');
    const publicUpload = find('[data-media-upload-public]');
    const uploadPreview = find('[data-media-upload-preview]');
    const uploadImage = find('[data-media-upload-image]');
    const uploadName = find('[data-media-upload-name]');
    const uploadSource = find('[data-media-upload-source]');
    const uploadStatus = find('[data-media-upload-status]');
    const uploadProgress = find('[data-media-upload-progress]');
    const collection = find('[data-media-collection]');
    const localFiles = new WeakMap();
    let active;
    let pending;
    let items = new Map();
    let page = 1;
    let lastPage = 1;
    let timer;
    let opener;
    let parentModal;
    let uploadController;
    let opening = 0;
    let collectionSource = 'public';
    let stagedFile;
    let stagedUrl;
    let stagedUrlOwned = false;
    let committing = false;
    const mixed = () => active?.dataset.mediaPrivacy === 'mixed';
    const privateCollection = () =>
        active?.dataset.mediaPrivacy === 'private' || (mixed() && collectionSource === 'private');
    const privateUpload = () => active?.dataset.mediaPrivacy === 'private' || (mixed() && !publicUpload.checked);
    const deferred = () => Boolean(active?.querySelector('[data-media-file]'));
    const draftPicker = (picker = active) => picker?.dataset['mediaDraft'] === 'true';
    const draftsFor = (picker = active) =>
        draftPicker(picker) ? (dependencies.drafts?.listMediaDraft ?? listMediaDraft)(picker.closest('form')) : [];
    const registeredDraft = (picker, id) =>
        draftPicker(picker) ? (dependencies.drafts?.getMediaDraft ?? getMediaDraft)(picker.closest('form'), id) : null;
    const localFor = (picker) => {
        const selected = readAsset(picker);
        const registered = registeredDraft(picker, selected?.local ? selected.id : null);
        return registered
            ? { ...registered, visibility: selected.visibility ?? registered.visibility }
            : localFiles.get(picker);
    };

    /**
     * @param {string} key
     * @param {Record<string, string|number>} [values]
     * @returns {string}
     */
    const text = (key, values = {}) =>
        Object.entries(values).reduce(
            (message, [name, value]) => message.replace(':' + name, String(value)),
            (privateCollection() ? labels['private_' + key] : null) ?? labels[key] ?? '',
        );
    const showError = (message = '') => {
        error.textContent = message;
        error.hidden = !message;
    };
    const readAsset = (picker) => {
        try {
            return JSON.parse(picker.dataset.mediaAsset || 'null');
        } catch {
            return null;
        }
    };
    const renderPicker = (picker, asset, value) => {
        const field = picker.querySelector('[data-media-value]');
        const preview = picker.querySelector('[data-media-preview]');
        const urlField = picker.querySelector('[data-media-url]');
        const local = registeredDraft(picker, asset?.local ? asset.id : null) ?? localFiles.get(picker);
        const address =
            local && local.url === asset?.url
                ? local.url
                : mediaAddress(
                      asset?.url ?? (picker.dataset.mediaMode === 'url' ? value : ''),
                      base,
                      picker.dataset.mediaPrivacy !== 'private' && asset?.visibility !== 'private',
                  );
        field.value = value;
        picker.dataset.mediaAsset = JSON.stringify(asset, (key, value) => (key === 'file' ? undefined : value));
        preview.hidden = !address;
        address ? preview.setAttribute('src', address) : preview.removeAttribute('src');
        picker.querySelector('[data-media-placeholder]').hidden = Boolean(address);
        picker.querySelector('[data-media-name]').textContent =
            asset?.name || (address ? value : picker.dataset.mediaEmpty);
        const dimensions = picker.querySelector('[data-media-dimensions]');
        dimensions.hidden = !asset?.width || !asset?.height;
        dimensions.textContent = dimensions.hidden ? '' : `${asset.width} × ${asset.height} px`;
        picker.querySelector('[data-media-clear]').hidden = !value && !local;
        const localStatus = picker.querySelector('[data-media-local-status]');
        if (localStatus) {
            localStatus.hidden = !local;
        }
        const source = picker.querySelector('[data-media-source]');
        if (source) {
            source.hidden = !asset;
            source.textContent = asset?.visibility === 'private' ? labels.source_private : labels.source_public;
        }
        if (urlField) {
            urlField.value = value;
        }
    };
    const releaseLocalFile = (picker, keepUrl = null) => {
        const local = localFiles.get(picker);
        if (local && local.url !== keepUrl) {
            URL.revokeObjectURL(local.url);
        }
        localFiles.delete(picker);
    };
    const changePicker = (picker, asset, value, local = null) => {
        clearMediaPickerErrors(picker);
        const file = picker.querySelector('[data-media-file]');
        releaseLocalFile(picker, local?.url);
        if (file) {
            file.value = '';
            file.name =
                local?.visibility === 'public' && picker.dataset.mediaPublicUploadName
                    ? picker.dataset.mediaPublicUploadName
                    : picker.dataset.mediaUploadName || file.name;
            if (local && !draftPicker(picker)) {
                const transfer = new DataTransfer();
                transfer.items.add(local.file);
                file.files = transfer.files;
                localFiles.set(picker, local);
                picker.closest('form')?.setAttribute('data-redirect-on-upload', 'true');
            }
        }
        if (asset?.local && draftPicker(picker)) {
            picker.closest('form')?.setAttribute('data-redirect-on-upload', 'true');
        }
        renderPicker(picker, asset, value);
        const field = picker.querySelector('[data-media-value]');
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        picker.dispatchEvent(new CustomEvent('media:change', { bubbles: true, detail: { asset, value } }));
    };
    const prepare = (picker) => {
        if (picker.dataset.mediaReady === 'true') {
            return;
        }
        picker.dataset.mediaReady = 'true';
        picker.dataset.mediaInitialAsset = picker.dataset.mediaAsset;
        const fallback = picker.querySelector('[data-media-direct-upload]');
        if (fallback) {
            fallback.hidden = true;
        }
        renderPicker(picker, readAsset(picker), picker.querySelector('[data-media-value]').value);
    };
    const updateCommitButton = () => {
        select.disabled = committing || (!pending && !stagedFile);
        select.textContent = committing ? labels.uploading : labels.select;
        pendingLabel.textContent = stagedFile?.name ?? pending?.name ?? '';
    };
    const clearStagedFile = (resetInput = true) => {
        if (stagedUrl && stagedUrlOwned) {
            URL.revokeObjectURL(stagedUrl);
        }
        stagedFile = undefined;
        stagedUrl = undefined;
        stagedUrlOwned = false;
        uploadPreview.hidden = true;
        uploadImage.removeAttribute('src');
        uploadName.textContent = '';
        uploadStatus.textContent = '';
        if (resetInput) {
            uploadFile.value = '';
        }
        updateCommitButton();
    };
    const selectPending = (asset) => {
        pending = asset;
        updateCommitButton();
        for (const button of collection.querySelectorAll('[data-media-asset-id]')) {
            const selected = String(asset?.id) === button.dataset.mediaAssetId;
            button.setAttribute('aria-pressed', String(selected));
            button.classList.toggle('border-primary', selected);
            button.classList.toggle('bg-primary-subtle', selected);
        }
    };
    const renderAssets = (records, target) => {
        for (const record of records) {
            const asset =
                mixed() && !record.local
                    ? { ...record, visibility: privateCollection() ? 'private' : 'public' }
                    : record;
            const local = registeredDraft(active, asset.local ? asset.id : null);
            const address =
                local && local.url === asset.url ? local.url : mediaAddress(asset.url, base, !privateCollection());
            const identifier = mixed() ? (privateCollection() ? 'uuid' : 'integer') : active.dataset.mediaIdentifier;
            if (!address || !mediaIdentifier(asset.id, identifier)) {
                continue;
            }
            items.set(String(asset.id), asset);
            const column = document.createElement('div');
            column.className = 'col-6 col-md-4 col-xl-3';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'media-picker-option btn text-start border rounded p-0 w-100 h-100 overflow-hidden';
            button.dataset.mediaAssetId = String(asset.id);
            const frame = document.createElement('div');
            frame.className = 'ratio ratio-4x3 bg-body-tertiary';
            const image = document.createElement('img');
            image.src = address;
            image.alt = '';
            image.loading = 'lazy';
            image.className = 'object-fit-contain p-2';
            frame.append(image);
            const description = document.createElement('span');
            description.className = 'd-block p-2';
            const name = document.createElement('span');
            name.className = 'd-block text-break small fw-semibold';
            name.textContent = asset.name;
            const dimensions = document.createElement('span');
            dimensions.className = 'd-block small text-body-secondary';
            dimensions.textContent = local ? labels.pending_save : `${asset.width} × ${asset.height} px`;
            description.append(name, dimensions);
            button.append(frame, description);
            column.append(button);
            target.append(column);
        }
    };
    const renderResults = (data) => {
        results.replaceChildren();
        draftResults.replaceChildren();
        items = new Map();
        const query = search.value.trim().toLocaleLowerCase();
        const local = privateCollection()
            ? draftsFor().filter((asset) => asset.name.toLocaleLowerCase().includes(query))
            : [];
        draftSection.hidden = local.length === 0;
        renderAssets(local, draftResults);
        renderAssets(data.items.slice(0, 24), results);
        const pagination = data.pagination;
        page = Math.max(1, Number(pagination.page ?? pagination.current_page) || 1);
        lastPage = Math.max(1, Number(pagination.last_page) || 1);
        const total = Math.max(0, Number(pagination.total) || 0) + local.length;
        status.textContent = items.size ? text(total === 1 ? 'result' : 'results', { total }) : text('empty');
        find('[data-media-pagination-label]').textContent = text(total === 1 ? 'page_single' : 'page', {
            page,
            pages: lastPage,
            total,
        });
        previous.disabled = page <= 1;
        next.disabled = page >= lastPage;
        selectPending(pending);
    };
    const load = async (requestedPage = 1) => {
        if (!active) {
            return;
        }
        try {
            showError();
            status.textContent = text('loading');
            results.setAttribute('aria-busy', 'true');
            previous.disabled = next.disabled = true;
            const parameters = [
                ['context', active.dataset.mediaContext],
                ['q', search.value.trim()],
                ['page', String(requestedPage)],
            ];
            for (const [key, value] of Object.entries(JSON.parse(active.dataset.mediaParameters || '{}'))) {
                if (typeof value === 'string' || typeof value === 'number') {
                    parameters.push([key, String(value)]);
                }
            }
            for (const mime of (active.dataset.mediaAccept ?? '').split(',')) {
                if (/^image\/[a-z0-9.+-]+$/i.test(mime.trim())) {
                    parameters.push(['mime[]', mime.trim()]);
                }
            }
            if (draftPicker() && privateCollection() && !active.dataset.mediaPrivateIndex) {
                requests.cancel();
                renderResults({ items: [], pagination: { page: 1, last_page: 1, total: 0 } });
                results.setAttribute('aria-busy', 'false');
                return;
            }
            const endpoint =
                mixed() && privateCollection()
                    ? active.dataset.mediaPrivateIndex
                    : (active.dataset.mediaIndex ?? modalElement.dataset.mediaIndex);
            const data = await requests.load(endpoint, parameters);
            if (data) {
                renderResults(data);
                results.setAttribute('aria-busy', 'false');
            }
        } catch {
            results.replaceChildren();
            results.setAttribute('aria-busy', 'false');
            status.textContent = '';
            showError(text('error'));
        }
    };
    const setCollection = (source, reload = true) => {
        collectionSource = source;
        find(source === 'private' ? '#media-private-pane' : '#media-library-pane').append(collection);
        search.value = '';
        search.placeholder = text('search');
        find('[data-media-modal-help]').textContent = text('selection_help');
        find('[data-media-pagination]').setAttribute('aria-label', text('pagination'));
        if (reload) {
            void load();
        }
    };
    const updateUploadOptions = () => {
        const restricted = privateUpload();
        find('[data-media-private-notice]').hidden = !restricted;
        find('[data-media-alt-group]').hidden = restricted || deferred();
        find('#media-selection-alt').disabled = restricted || deferred();
        const help = find('[data-media-upload-help]');
        help.textContent = deferred()
            ? [
                  labels.upload_deferred_help,
                  mixed() ? '' : (active.querySelector('[data-media-help]')?.textContent ?? ''),
              ]
                  .filter(Boolean)
                  .join(' ')
            : restricted
              ? ''
              : mixed()
                ? labels.upload_public_help
                : (active.querySelector('[data-media-help]')?.textContent ?? '');
        help.hidden = !help.textContent;
        uploadSource.textContent = restricted ? labels.source_private : labels.source_public;
    };
    const setCommitting = (busy) => {
        committing = busy;
        uploadFile.disabled = busy;
        publicUpload.disabled = busy;
        find('#media-selection-alt').disabled = busy || privateUpload() || deferred();
        uploadProgress.hidden = !busy;
        upload.setAttribute('aria-busy', String(busy));
        uploadStatus.textContent = busy
            ? labels.uploading
            : stagedFile
              ? deferred()
                  ? labels.upload_staged
                  : labels.upload_ready
              : '';
        updateCommitButton();
    };
    const open = (picker, button) => {
        prepare(picker);
        active = picker;
        opener = button;
        opening++;
        search.value = '';
        results.replaceChildren();
        find('[data-media-pagination-label]').textContent = '';
        upload.reset();
        clearStagedFile();
        setCommitting(false);
        uploadFile.accept = picker.dataset.mediaAccept;
        const privateImages = picker.dataset.mediaPrivacy === 'private';
        const hasPrivateCollection = mixed() && (draftPicker(picker) || Boolean(picker.dataset.mediaPrivateIndex));
        const local = localFor(picker);
        find('[data-media-private-tab-item]').hidden = !hasPrivateCollection;
        find('[data-media-public-upload-group]').hidden = !mixed();
        publicUpload.checked = Boolean(local && local.visibility === 'public');
        publicUpload.disabled = false;
        find('[data-media-library-label]').textContent = privateImages ? labels.private_library : labels.library;
        const initialSource = hasPrivateCollection && readAsset(picker)?.visibility !== 'public' ? 'private' : 'public';
        setCollection(initialSource, false);
        updateUploadOptions();
        find('#media-selection-title').textContent = picker.dataset.mediaLabel;
        selectPending(local ? null : readAsset(picker));
        TabClass.getOrCreateInstance(
            find(
                local || (mixed() && !hasPrivateCollection && !readAsset(picker))
                    ? '#media-upload-tab'
                    : hasPrivateCollection && initialSource === 'private'
                      ? '#media-private-tab'
                      : privateImages && !readAsset(picker)
                        ? '#media-upload-tab'
                        : '#media-library-tab',
            ),
        ).show();
        if (local) {
            stagedFile = local.file;
            stagedUrl = local.url;
            stagedUrlOwned = false;
            const transfer = new DataTransfer();
            transfer.items.add(local.file);
            uploadFile.files = transfer.files;
            uploadImage.src = stagedUrl;
            uploadName.textContent = local.file.name;
            uploadPreview.hidden = false;
            uploadStatus.textContent = labels.upload_staged;
            updateCommitButton();
        }
        parentModal = picker.closest('.modal.show');
        if (parentModal) {
            parentModal.addEventListener('hidden.bs.modal', () => modal.show(button), { once: true });
            ModalClass.getOrCreateInstance(parentModal).hide();
        } else {
            modal.show(button);
        }
        void load();
    };

    root.querySelectorAll('[data-media-picker]').forEach(prepare);
    root.addEventListener('kraft:content-added', () => root.querySelectorAll('[data-media-picker]').forEach(prepare));
    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-media-open], [data-media-clear]');
        const picker = button?.closest('[data-media-picker]');
        if (!picker) {
            return;
        }
        prepare(picker);
        if (button.hasAttribute('data-media-clear')) {
            changePicker(picker, null, '');
        } else {
            open(picker, button);
        }
    });
    root.addEventListener('input', (event) => {
        if (event.target.matches('[data-media-url]')) {
            const picker = event.target.closest('[data-media-picker]');
            prepare(picker);
            changePicker(picker, null, event.target.value);
        }
    });
    root.addEventListener('reset', (event) => {
        if (event.target === upload) {
            return;
        }
        setTimeout(() => {
            event.target.querySelectorAll('[data-media-picker]').forEach((picker) => {
                prepare(picker);
                releaseLocalFile(picker);
                const file = picker.querySelector('[data-media-file]');
                if (file && picker.dataset.mediaUploadName) {
                    file.name = picker.dataset.mediaUploadName;
                }
                let asset;
                try {
                    asset = JSON.parse(picker.dataset.mediaInitialAsset || 'null');
                } catch {
                    asset = null;
                }
                renderPicker(picker, asset, picker.querySelector('[data-media-value]').value);
            });
        }, 0);
    });
    root.addEventListener('ajax:error', (event) => {
        let focused = false;
        const firstError = Object.keys(event.detail?.data?.errors ?? {})[0];
        for (const picker of event.target.querySelectorAll('[data-media-picker]')) {
            for (const feedback of picker.querySelectorAll('[data-media-direct-upload] .invalid-feedback')) {
                picker.append(feedback);
            }
            const field =
                picker.querySelector('input[aria-invalid="true"]') ?? picker.querySelector('[data-media-value]');
            const button = picker.querySelector('[data-media-open]');
            if (field.getAttribute('aria-invalid') === 'true') {
                button.classList.add('is-invalid');
                button.setAttribute('aria-invalid', 'true');
                button.setAttribute('aria-describedby', field.getAttribute('aria-describedby') ?? '');
                const fieldKey = field.name.replace(/\[([^\]]*)\]/g, '.$1').replace(/\.$/, '');
                if (!focused && firstError === fieldKey) {
                    button.focus();
                    focused = true;
                }
            }
        }
    });
    root.addEventListener('ajax:success', (event) => {
        for (const picker of event.target.querySelectorAll('[data-media-picker]')) {
            clearMediaPickerErrors(picker);
        }
    });
    find('[data-media-search]').addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(timer);
        void load();
    });
    find('#media-private-tab').addEventListener('show.bs.tab', (event) => {
        if (committing) {
            event.preventDefault();
            return;
        }
        clearStagedFile();
        if (mixed() && collectionSource !== 'private') {
            setCollection('private');
        }
    });
    find('#media-library-tab').addEventListener('show.bs.tab', (event) => {
        if (committing) {
            event.preventDefault();
            return;
        }
        clearStagedFile();
        if (mixed() && collectionSource !== 'public') {
            setCollection('public');
        }
    });
    find('#media-upload-tab').addEventListener('show.bs.tab', (event) => {
        if (committing) {
            event.preventDefault();
        }
    });
    publicUpload.addEventListener('change', updateUploadOptions);
    uploadFile.addEventListener('change', () => {
        if (!active || committing) {
            return;
        }
        clearStagedFile(false);
        selectPending(null);
        showError();
        const file = uploadFile.files?.[0];
        if (!file) {
            return;
        }
        const accepted = (active.dataset.mediaAccept ?? '').split(',').map((value) => value.trim());
        if (file.size === 0 || (file.type && accepted.length && !accepted.includes(file.type))) {
            showError(text('upload_error'));
            return;
        }
        stagedFile = file;
        stagedUrl = URL.createObjectURL(file);
        stagedUrlOwned = true;
        uploadImage.src = stagedUrl;
        uploadName.textContent = file.name;
        uploadPreview.hidden = false;
        uploadStatus.textContent = deferred() ? labels.upload_staged : labels.upload_ready;
        updateUploadOptions();
        updateCommitButton();
    });
    search.addEventListener('input', () => {
        clearTimeout(timer);
        requests.cancel();
        timer = setTimeout(() => load(), 250);
    });
    previous.addEventListener('click', () => load(page - 1));
    next.addEventListener('click', () => load(page + 1));
    collection.addEventListener('click', (event) => {
        const button = event.target.closest('[data-media-asset-id]');
        if (button && !committing) {
            clearStagedFile();
            selectPending(items.get(button.dataset.mediaAssetId));
        }
    });
    const applySelection = (asset) => {
        if (asset.local && draftPicker()) {
            const registered = registeredDraft(active, asset.id);
            if (!registered || registered.url !== asset.url) {
                throw new Error(text('upload_error'));
            }
            changePicker(active, asset, asset.id);
            select.disabled = true;
            modal.hide();
            return;
        }
        const identifier = mixed()
            ? asset.visibility === 'private'
                ? 'uuid'
                : 'integer'
            : active.dataset.mediaIdentifier;
        if (!mediaIdentifier(asset.id, identifier) || !mediaAddress(asset.url, base, asset.visibility !== 'private')) {
            throw new Error(text('upload_error'));
        }
        let value = String(asset.id);
        if (active.dataset.mediaMode === 'url') {
            const url = new URL(asset.url, base);
            value = url.origin === new URL(base).origin ? url.pathname + url.search + url.hash : url.href;
        }
        changePicker(active, asset, value);
        select.disabled = true;
        modal.hide();
    };
    select.addEventListener('click', () => commit());
    modalElement.addEventListener('shown.bs.modal', () => {
        find('#media-upload-pane').classList.contains('show') ? uploadFile.focus() : search.focus();
    });
    modalElement.addEventListener('hide.bs.modal', () => {
        opening++;
        requests.cancel();
        uploadController?.abort();
    });
    modalElement.addEventListener('hidden.bs.modal', () => {
        clearTimeout(timer);
        requests.cancel();
        uploadController?.abort();
        pending = null;
        clearStagedFile();
        setCommitting(false);
        active = null;
        results.replaceChildren();
        if (parentModal) {
            parentModal.addEventListener('shown.bs.modal', () => opener?.focus(), { once: true });
            ModalClass.getOrCreateInstance(parentModal).show();
            parentModal = null;
        } else {
            opener?.focus();
        }
    });
    const commit = async () => {
        if (!active || committing || (!pending && !stagedFile)) {
            return;
        }
        if (!stagedFile) {
            try {
                applySelection(pending);
            } catch (failure) {
                showError(failure.message);
            }
            return;
        }
        if (deferred()) {
            try {
                const visibility = privateUpload() ? 'private' : 'public';
                if (draftPicker()) {
                    const registered = (dependencies.drafts?.stageMedia ?? stageMedia)(
                        active.closest('form'),
                        stagedFile,
                        {
                            url: stagedUrl,
                            visibility,
                        },
                    );
                    if (stagedUrlOwned && registered.url !== stagedUrl) {
                        URL.revokeObjectURL(stagedUrl);
                    }
                    stagedUrlOwned = false;
                    applySelection({ ...registered, visibility });
                    return;
                }
                const asset = { id: null, name: stagedFile.name, url: stagedUrl, visibility, local: true };
                const local = { file: stagedFile, url: stagedUrl, visibility };
                changePicker(active, asset, '', local);
                stagedUrlOwned = false;
                select.disabled = true;
                modal.hide();
            } catch (failure) {
                showError(failure.message || text('upload_error'));
            }
            return;
        }
        if (!upload.reportValidity()) {
            return;
        }
        const ticket = opening;
        let applied = false;
        try {
            const restrictedUpload = privateUpload();
            const endpoint =
                mixed() && restrictedUpload
                    ? active.dataset.mediaPrivateStore
                    : (active.dataset.mediaStore ?? modalElement.dataset.mediaStore);
            const address = mediaAddress(endpoint, base);
            if (!address) {
                showError(text('upload_error'));
                return;
            }
            showError();
            uploadController = new AbortController();
            const body = new FormData(upload);
            body.set('file', stagedFile, stagedFile.name);
            body.set('context', active.dataset.mediaContext);
            for (const [key, value] of Object.entries(JSON.parse(active.dataset.mediaParameters || '{}'))) {
                if (typeof value === 'string' || typeof value === 'number') {
                    body.set(key, String(value));
                }
            }
            setCommitting(true);
            const response = await fetcher(address, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body,
                signal: uploadController.signal,
                redirect: 'error',
            });
            const envelope = await response.json().catch(() => null);
            const data = envelope?.data;
            if (opening !== ticket) {
                return;
            }
            if (!response.ok || envelope?.success !== true || !data?.asset) {
                const messages = Object.values(data?.errors ?? {})
                    .flat()
                    .filter((value) => typeof value === 'string');
                throw new Error(
                    response.status === 419 ? text('session_expired') : messages[0] || text('upload_error'),
                );
            }
            const asset = mixed() ? { ...data.asset, visibility: restrictedUpload ? 'private' : 'public' } : data.asset;
            applySelection(asset);
            applied = true;
        } catch (failure) {
            if (opening === ticket && failure.name !== 'AbortError') {
                showError(
                    failure.name === 'SyntaxError' ? text('upload_error') : failure.message || text('upload_error'),
                );
            }
        } finally {
            if (opening === ticket && !applied) {
                setCommitting(false);
                updateUploadOptions();
            }
        }
    };
    upload.addEventListener('submit', (event) => {
        event.preventDefault();
        void commit();
    });
    root.addEventListener('media-draft:change', (event) => {
        if (active && draftPicker() && event.target === active.closest('form') && privateCollection()) {
            void load(page);
        }
    });
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver((records) => {
            for (const record of records) {
                for (const node of record.removedNodes) {
                    const pickers = [
                        ...(node.matches?.('[data-media-picker]') ? [node] : []),
                        ...(node.querySelectorAll?.('[data-media-picker]') ?? []),
                    ];
                    for (const picker of pickers) {
                        if (!picker.isConnected) {
                            releaseLocalFile(picker);
                            if (active === picker) {
                                modal.hide();
                            }
                        }
                    }
                }
            }
        });
        observer.observe(root.documentElement ?? root, { childList: true, subtree: true });
    }
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initMediaPickers());
    } else {
        initMediaPickers();
    }
}
