import Modal from 'bootstrap/js/dist/modal';
import Collapse from 'bootstrap/js/dist/collapse';
import Tab from 'bootstrap/js/dist/tab';
import 'bootstrap/js/dist/dropdown';
import 'bootstrap/js/dist/alert';
import Carousel from 'bootstrap/js/dist/carousel';
import './admin/pagination.js';
import './tables.js';
import './table-dropdowns.js';
import './confirmation.js';
import './confirmation-hints.js';
import './auth-dialog.js';
import './game-login.js';
import './passkeys.js';
import * as mediaDrafts from './media-drafts.js';
import { createEditorAppearance } from './editor-appearance.js';

/** @type {WeakMap<object, Promise<unknown>>} */
const editorInitializations = new WeakMap();
const initializedControls = new WeakMap();
const editorMediaScans = new WeakMap();
const editorMediaFilters = new WeakSet();
const savingEditorForms = new WeakSet();
const savedMediaForms = new WeakSet();
/** @type {Record<string, string>} */
const mediaDraftMessages = {};

function confirmedFetch(form, input, options) {
    return window.KraftConfirmation ? window.KraftConfirmation.fetch(form, input, options) : fetch(input, options);
}

function confirmationCancelled(error) {
    return window.KraftConfirmation?.isCancelled(error) ?? false;
}

function coreContentForm(form) {
    return Boolean(form?.hasAttribute?.('data-content-form'));
}

function normalizeEditorMedia(editor) {
    const form = editor.getElement().form;
    for (const image of editor.getBody?.()?.querySelectorAll('img') ?? []) {
        const asset = mediaDrafts.findMediaDraftByUrl(form, image.getAttribute('src') ?? '');
        if (asset) {
            image.setAttribute('src', asset.url);
            image.setAttribute('data-mce-src', asset.url);
        }
    }
}

function installEditorMediaFilter(editor) {
    if (editorMediaFilters.has(editor) || !editor.editorUpload?.addFilter) {
        return;
    }
    editorMediaFilters.add(editor);
    editor.editorUpload.addFilter((image) => {
        const form = editor.getElement().form;
        if (savingEditorForms.has(form) && !coreContentForm(form)) {
            return true;
        }
        return !mediaDrafts.findMediaDraftByUrl(form, image.getAttribute('src') ?? '');
    });
}

function captureEditorMedia(editor) {
    const field = editor.getElement();
    if (!field.form || !editor.editorUpload?.scanForImages) {
        return Promise.resolve();
    }
    normalizeEditorMedia(editor);
    installEditorMediaFilter(editor);
    const pending = editorMediaScans.get(editor);
    if (pending) {
        return pending;
    }
    const scan = Promise.resolve()
        .then(async () => {
            const images = await editor.editorUpload.scanForImages();
            for (const { image, blobInfo } of images ?? []) {
                if (!blobInfo?.blob) {
                    continue;
                }
                const source = image?.getAttribute('src') ?? blobInfo.blobUri();
                mediaDrafts.stageMedia(field.form, blobInfo.blob(), {
                    name: blobInfo.filename(),
                    url: blobInfo.blobUri(),
                    aliases: [source],
                    field,
                });
            }
            normalizeEditorMedia(editor);
            editor.save();
        })
        .finally(() => editorMediaScans.delete(editor));
    editorMediaScans.set(editor, scan);
    return scan;
}

function pendingMediaForms(root = document) {
    const candidates = new Set([...mediaDrafts.mediaDraftForms(), ...(root.querySelectorAll?.('form') ?? [])]);
    if (root.tagName === 'FORM') {
        candidates.add(root);
    }
    return [...candidates].filter((form) => {
        if (savedMediaForms.has(form) || (root !== document && root !== form && !root.contains?.(form))) {
            return false;
        }
        return (
            mediaDrafts.hasReferencedMediaDraft(form) ||
            Array.from(form.elements ?? []).some(
                (field) => !field.disabled && field.type === 'file' && field.files?.length,
            )
        );
    });
}

function initializeControl(element, name) {
    const names = initializedControls.get(element) || new Set();
    if (names.has(name)) {
        return false;
    }
    names.add(name);
    initializedControls.set(element, names);
    return true;
}

function initializeCompletionStatuses() {
    const update = (group) => {
        const badge = group.querySelector('[data-completion-status]');
        if (!badge) {
            return;
        }
        const filled = [...group.querySelectorAll('input, textarea')].some((field) => {
            if (!field.hasAttribute('data-editor')) {
                return field.value.trim() !== '';
            }
            const template = document.createElement('template');
            template.innerHTML = field.value;
            return template.content.querySelector('img, hr') || /[^\s\p{Z}]/u.test(template.content.textContent);
        });
        badge.classList.toggle('text-bg-success', filled);
        badge.classList.toggle('text-bg-secondary', !filled);
        const label = filled ? badge.dataset['filledLabel'] : badge.dataset['emptyLabel'];
        if (badge.textContent !== label) {
            badge.textContent = label;
        }
    };
    document.querySelectorAll('[data-completion-group]').forEach(update);
    for (const event of ['input', 'editor:change']) {
        document.addEventListener(event, (event) => {
            const group = event.target.closest('[data-completion-group]');
            if (group) {
                update(group);
            }
        });
    }
}

initializeCompletionStatuses();

export function normalizeSlug(value, { paths = false, final = true, max = 160 } = {}) {
    const letters = { œ: 'oe', æ: 'ae', ß: 'ss', ø: 'o', ł: 'l', đ: 'd', ð: 'd', þ: 'th' };
    let result = String(value ?? '')
        .toLowerCase()
        .normalize('NFKD')
        .replace(/\p{M}+/gu, '')
        .replace(/[œæßøłđðþ]/g, (letter) => letters[letter]);
    result = result.replace(paths ? /[^a-z0-9/]+/g : /[^a-z0-9]+/g, '-').replace(/-+/g, '-');
    if (paths) {
        result = result.replace(/\/+/g, '/').replace(/-*\//g, '/').replace(/\/-*/g, '/');
    }
    result = result.replace(/^[-/]+/, '').slice(0, max);
    return final ? result.replace(/[-/]+$/, '') : result;
}

function revealDetails(field) {
    for (let ancestor = field.parentElement; ancestor; ancestor = ancestor.parentElement) {
        if (ancestor.tagName === 'DETAILS') {
            ancestor.open = true;
        }
    }
}

document.addEventListener('invalid', (event) => revealDetails(event.target), true);

async function revealFormField(field) {
    revealDetails(field);
    const panels = [];
    for (let ancestor = field.parentElement; ancestor; ancestor = ancestor.parentElement) {
        if (
            ancestor.classList.contains('collapse') ||
            ancestor.classList.contains('collapsing') ||
            ancestor.classList.contains('tab-pane')
        ) {
            panels.unshift(ancestor);
        }
    }
    for (const panel of panels) {
        if (panel.classList.contains('tab-pane') && !panel.classList.contains('active')) {
            const trigger = document.querySelector(`[data-bs-toggle="tab"][data-bs-target="#${CSS.escape(panel.id)}"]`);
            if (trigger) {
                await new Promise((resolve) => {
                    trigger.addEventListener('shown.bs.tab', resolve, { once: true });
                    Tab.getOrCreateInstance(trigger).show();
                    if (!panel.classList.contains('active')) {
                        trigger.removeEventListener('shown.bs.tab', resolve);
                        resolve();
                    }
                });
            }
        } else if (panel.classList.contains('collapse') || panel.classList.contains('collapsing')) {
            if (panel.classList.contains('collapsing')) {
                await new Promise((resolve) => {
                    const complete = () => {
                        panel.removeEventListener('shown.bs.collapse', complete);
                        panel.removeEventListener('hidden.bs.collapse', complete);
                        resolve();
                    };
                    panel.addEventListener('shown.bs.collapse', complete);
                    panel.addEventListener('hidden.bs.collapse', complete);
                });
            }
            if (!panel.classList.contains('show')) {
                await new Promise((resolve) => {
                    panel.addEventListener('shown.bs.collapse', resolve, { once: true });
                    Collapse.getOrCreateInstance(panel, { toggle: false }).show();
                    if (!panel.classList.contains('show') && !panel.classList.contains('collapsing')) {
                        panel.removeEventListener('shown.bs.collapse', resolve);
                        resolve();
                    }
                });
            }
        }
    }
}

function initializeCaptchas() {
    const widgets = new WeakMap();
    const scripts = new Map();
    const apis = { turnstile: 'turnstile', hcaptcha: 'hcaptcha', recaptcha: 'grecaptcha' };

    function load(element) {
        const provider = element.dataset.captchaProvider;
        const api = element.dataset['captchaApi'] || apis[provider];
        if (window[api]?.render) {
            return Promise.resolve(window[api]);
        }
        if (!scripts.has(provider)) {
            scripts.set(
                provider,
                new Promise((resolve, reject) => {
                    const callback = 'kraftCaptchaReady_' + provider.replace(/\W/g, '_');
                    window[callback] = () => {
                        resolve(window[api]);
                        delete window[callback];
                    };
                    const url = new URL(element.dataset.captchaScript);
                    url.searchParams.set('render', 'explicit');
                    url.searchParams.set('onload', callback);
                    url.searchParams.set('hl', document.documentElement.lang);
                    if (provider === 'hcaptcha') {
                        url.searchParams.set('recaptchacompat', 'off');
                    }
                    const script = document.createElement('script');
                    script.src = url.href;
                    script.async = true;
                    script.addEventListener(
                        'error',
                        () => {
                            scripts.delete(provider);
                            delete window[callback];
                            script.remove();
                            reject(new Error('captcha'));
                        },
                        { once: true },
                    );
                    document.head.append(script);
                }),
            );
        }
        return scripts.get(provider);
    }

    async function render(root = document) {
        const elements = Array.from(root.querySelectorAll('[data-captcha-provider]'));
        if (root.matches?.('[data-captcha-provider]')) {
            elements.unshift(root);
        }
        await Promise.all(
            elements.map(async (element) => {
                if (widgets.has(element)) {
                    return;
                }
                widgets.set(element, null);
                try {
                    const api = await load(element);
                    const id = api.render(element, {
                        sitekey: element.dataset.sitekey,
                        action: element.dataset.action,
                        hl: document.documentElement.lang,
                        theme: element.dataset.theme ?? 'light',
                        size: element.dataset.size ?? 'normal',
                    });
                    widgets.set(element, { api, id });
                } catch {
                    widgets.delete(element);
                    element.dispatchEvent(new CustomEvent('captcha:error', { bubbles: true }));
                }
            }),
        );
    }

    function reset(form) {
        form.querySelectorAll('[data-captcha-provider]').forEach((element) => {
            const widget = widgets.get(element);
            if (widget) {
                try {
                    widget.api.reset(widget.id);
                } catch {
                    element.dispatchEvent(new CustomEvent('captcha:error', { bubbles: true }));
                }
            }
        });
    }

    window.KraftCaptcha = { render, reset };
    void render();
}

function initializeForms() {
    const configuration = {
        classes: {
            message: 'alert',
            success: 'alert-success',
            error: 'alert-danger',
            warning: 'alert-warning',
            loading: 'spinner-border spinner-border-sm',
        },
        titles: {},
        icons: {
            success: 'bi bi-check-circle-fill',
            error: 'bi bi-x-circle-fill',
            warning: 'bi bi-exclamation-triangle-fill',
        },
        messages: /** @type {Record<string, string>} */ ({}),
        scroll: true,
    };
    const pending = new WeakSet();
    const errors = new WeakMap();
    let sequence = 0;

    function configure(options = {}) {
        for (const key of ['messages', 'classes', 'titles', 'icons']) {
            configuration[key] = { ...configuration[key], ...options[key] };
        }
        for (const key of ['redirectDelay', 'renderMessage', 'scroll']) {
            if (key in options) {
                configuration[key] = options[key];
            }
        }
        Object.assign(mediaDraftMessages, configuration.messages);
    }

    function message(form, type, text, { page = false } = {}) {
        const messages = [
            ...new Set(
                [text]
                    .flat(Infinity)
                    .filter((value) => typeof value === 'string')
                    .map((value) => value.trim())
                    .filter(Boolean),
            ),
        ];
        const modal = form.closest('.modal');
        const outsideModal = modal && messages.length > 0 &&
            (page || (type !== 'error' && !modal.hasAttribute('data-auth-dialog')));
        let target;
        try {
            target = form.dataset.messageTarget ? document.querySelector(form.dataset.messageTarget) : null;
        } catch {}
        if (outsideModal && target?.closest('.modal')) {
            target = null;
        }
        if (outsideModal) {
            target ??= document.querySelector('[data-page-messages]');
        } else {
            target ??= form.querySelector('[data-ajax-messages]');
            target ??= modal
                ? modal.querySelector('[data-ajax-messages]')
                : document.querySelector('[data-page-messages]');
        }
        if (!target) {
            target = document.createElement('div');
            target.dataset.ajaxMessages = '';
            if (outsideModal) {
                target.dataset.pageMessages = '';
            }
            const container = outsideModal
                ? (document.querySelector('#main-content') ?? document.querySelector('main') ?? document.body)
                : (modal?.querySelector('.modal-body') ?? form.querySelector('.modal-body, .card-body') ?? form);
            container.prepend(target);
        }
        target.replaceChildren();
        target.hidden = messages.length === 0;
        let rendered = false;
        if (configuration.renderMessage) {
            try {
                configuration.renderMessage({
                    form,
                    type,
                    message: messages.length > 1 ? messages : (messages[0] ?? ''),
                    messages,
                    target,
                });
                rendered = true;
            } catch {}
        }
        if (messages.length && !rendered) {
            const alert = document.createElement('div');
            alert.className = [
                configuration.classes?.message,
                configuration.classes?.[type],
                'd-flex align-items-start gap-3',
            ]
                .filter(Boolean)
                .join(' ');
            alert.setAttribute('role', type === 'error' ? 'alert' : 'status');
            const icon = document.createElement('i');
            icon.className = [configuration.icons[type], 'flex-shrink-0 fs-4 lh-1 mt-1'].filter(Boolean).join(' ');
            icon.setAttribute('aria-hidden', 'true');
            const content = document.createElement('div');
            content.className = 'flex-grow-1 text-break';
            const title = document.createElement('strong');
            title.dataset.ajaxTitle = '';
            title.className = 'd-block mb-1';
            title.textContent = configuration.titles[type] ?? configuration.messages[type + '_title'] ?? '';
            title.hidden = !title.textContent;
            const body = document.createElement('div');
            body.dataset.ajaxMessageBody = '';
            if (messages.length === 1) {
                body.textContent = messages[0];
            } else {
                const list = document.createElement('ul');
                list.className = 'mb-0';
                for (const value of messages) {
                    const item = document.createElement('li');
                    item.textContent = value;
                    list.append(item);
                }
                body.append(list);
            }
            content.append(title, body);
            alert.append(icon, content);
            target.append(alert);
        }
        if (messages.length && configuration.scroll && form.dataset.scroll !== 'false') {
            target.setAttribute('tabindex', '-1');
            target.style.scrollMarginTop = '6rem';
            target.focus({ preventScroll: true });
            target.scrollIntoView({
                behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
                block: 'start',
            });
        }
    }

    function clearErrors(form) {
        for (const [field, previous] of errors.get(form) ?? []) {
            field.classList.toggle('is-invalid', previous.invalid);
            for (const attribute of ['aria-invalid', 'aria-describedby']) {
                const value = previous[attribute];
                value === null ? field.removeAttribute(attribute) : field.setAttribute(attribute, value);
            }
        }
        errors.delete(form);
        form.querySelectorAll('.invalid-feedback, [data-modal-errors]').forEach((element) => {
            if (element.id) {
                for (const field of form.elements) {
                    const references = field.getAttribute('aria-describedby')?.split(/\s+/);
                    if (references?.includes(element.id)) {
                        field.setAttribute('aria-describedby', references.filter((id) => id !== element.id).join(' '));
                    }
                }
            }
            element.remove();
        });
        form.querySelectorAll('.is-invalid').forEach((field) => {
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
        });
    }

    async function showErrors(form, messages = {}) {
        const previous = new Map();
        for (const [name, values] of Object.entries(messages)) {
            const fields = Array.from(form.elements).filter((field) => {
                const key = field.name?.replace(/\[([^\]]*)\]/g, '.$1').replace(/\.$/, '');
                return key && (key === name || name.startsWith(key + '.'));
            });
            if (!fields.length) {
                continue;
            }
            const feedback = document.createElement('div');
            feedback.id = 'kraft-form-error-' + ++sequence;
            feedback.dataset.ajaxError = '';
            feedback.className = 'invalid-feedback d-block';
            feedback.textContent = [values].flat().join(' ');
            for (const field of fields) {
                if (!previous.has(field)) {
                    previous.set(field, {
                        invalid: field.classList.contains('is-invalid'),
                        'aria-invalid': field.getAttribute('aria-invalid'),
                        'aria-describedby': field.getAttribute('aria-describedby'),
                    });
                }
                field.classList.add('is-invalid');
                field.setAttribute('aria-invalid', 'true');
                field.setAttribute(
                    'aria-describedby',
                    [field.getAttribute('aria-describedby'), feedback.id].filter(Boolean).join(' '),
                );
            }
            const field = fields[0];
            (window.tinymce?.get(field.id)?.getContainer() ?? field.closest('.input-group') ?? field).after(feedback);
        }
        errors.set(form, previous);
        const field = previous.keys().next().value;
        if (field) {
            await revealFormField(field);
            await editorInitializations.get(field);
            const editor = window.tinymce?.get(field.id);
            editor && editor.initialized !== false ? editor.focus() : field.focus();
        }
    }

    function editors(form) {
        return (window.tinymce?.get() ?? []).filter(
            (editor) => editor.initialized !== false && editor.getElement().form === form,
        );
    }

    function saveEditors(form) {
        editors(form).forEach((editor) => editor.save());
    }

    function loading(form, button) {
        const controls = Array.from(form.elements).filter((field) => ['submit', 'image'].includes(field.type));
        const disabled = controls.map((field) => field.disabled);
        const busy = form.getAttribute('aria-busy');
        form.setAttribute('aria-busy', 'true');
        controls.forEach((field) => {
            field.disabled = true;
        });
        let restore = () => {};
        if (button) {
            const style = button.getAttribute('style');
            const label = button.getAttribute('aria-label');
            const rectangle = button.getBoundingClientRect();
            const nodes = Array.from(button.childNodes);
            const value = button.value;
            button.style.width = rectangle.width + 'px';
            button.style.height = rectangle.height + 'px';
            button.setAttribute('aria-label', configuration.messages.loading);
            if (button.tagName === 'INPUT') {
                button.value = configuration.messages.loading;
            } else {
                const spinner = document.createElement('span');
                spinner.className = configuration.classes.loading;
                spinner.setAttribute('aria-hidden', 'true');
                const text = document.createElement('span');
                text.className = rectangle.width < 150 ? 'visually-hidden' : 'ms-2';
                text.textContent = configuration.messages.loading;
                button.replaceChildren(spinner, text);
            }
            restore = () => {
                button.tagName === 'INPUT' ? (button.value = value) : button.replaceChildren(...nodes);
                style === null ? button.removeAttribute('style') : button.setAttribute('style', style);
                label === null ? button.removeAttribute('aria-label') : button.setAttribute('aria-label', label);
            };
        }
        return () => {
            restore();
            controls.forEach((field, index) => {
                field.disabled = disabled[index];
            });
            busy === null ? form.removeAttribute('aria-busy') : form.setAttribute('aria-busy', busy);
        };
    }

    function callback(name) {
        let owner = window;
        const parts = name?.split('.') ?? [];
        for (const part of parts) {
            if (!/^[a-zA-Z_$][\w$]*$/.test(part) || ['__proto__', 'prototype', 'constructor'].includes(part)) {
                return null;
            }
            owner = owner?.[part];
        }
        return typeof owner === 'function' ? owner : null;
    }

    async function closeActionModal(form) {
        const modal = form.closest('.modal');
        if (!modal || form.dataset.closeModalOnSuccess !== 'true') {
            return false;
        }
        await Promise.all(
            [modal, modal.querySelector('.modal-dialog')]
                .filter(Boolean)
                .flatMap((element) => element.getAnimations?.() ?? [])
                .map((animation) => animation.finished.catch(() => {})),
        );
        if (!modal.classList.contains('show')) {
            return true;
        }
        return new Promise((resolve) => {
            const complete = () => {
                modal.removeEventListener('hide.bs.modal', started);
                resolve(true);
            };
            const started = (event) => {
                Promise.resolve().then(() => {
                    if (event.defaultPrevented) {
                        modal.removeEventListener('hidden.bs.modal', complete);
                        resolve(false);
                    }
                });
            };
            modal.addEventListener('hide.bs.modal', started, { once: true });
            modal.addEventListener('hidden.bs.modal', complete, { once: true });
            Modal.getOrCreateInstance(modal).hide();
        });
    }

    function redirect(value, data, form) {
        const destination = value === 'response' ? data.redirect_url : value;
        if (typeof destination !== 'string' || !destination.trim() || destination === 'false') {
            return null;
        }
        try {
            const url = new URL(destination, window.location.href);
            const external =
                form?.dataset.externalRedirect === 'oauth' &&
                data.redirect_external === true &&
                url.protocol === 'https:';
            if ((url.origin === window.location.origin || external) && !url.username && !url.password) {
                return url.href;
            }
        } catch {}
        return null;
    }

    /**
     * @param {HTMLFormElement} form
     * @param {HTMLButtonElement|HTMLInputElement|null} [button]
     */
    async function submit(form, button) {
        if (pending.has(form)) {
            return;
        }
        pending.add(form);
        clearErrors(form);
        message(form, 'success', '');
        const submitter = button?.name ? [button.name, button.value] : null;
        const uploaded =
            form.dataset.redirectOnUpload === 'true' &&
            Array.from(form.elements).some((field) => field.type === 'file' && field.files.length);
        let navigation = uploaded ? 'response' : form.dataset.redirectUrl;
        const restore = loading(form, button);
        let data;
        let response;
        let sent = false;
        let succeeded = false;
        let closedModal = false;
        let failure;
        let restoreInert = () => {};
        try {
            await Promise.all(
                Array.from(form.querySelectorAll('textarea[data-editor]')).map((field) =>
                    editorInitializations.get(field),
                ),
            );
            const instances = editors(form);
            await Promise.all(instances.map((editor) => editorMediaScans.get(editor))).catch(() => {
                throw new Error('local_image_unavailable');
            });
            await Promise.all(instances.map(captureEditorMedia)).catch(() => {
                throw new Error('local_image_unavailable');
            });
            if (!coreContentForm(form)) {
                savingEditorForms.add(form);
                try {
                    const uploads = await Promise.all(instances.map((editor) => editor.uploadImages())).catch(() => {
                        throw new Error(configuration.messages.upload_error);
                    });
                    if (uploads.some((results) => results.some((result) => !result.status))) {
                        throw new Error(configuration.messages.upload_error);
                    }
                } finally {
                    savingEditorForms.delete(form);
                }
            }
            saveEditors(form);
            const body = new FormData(form);
            if (mediaDrafts.augmentDraftFormData(form, body)) {
                navigation = 'response';
            }
            if (submitter) {
                body.append(submitter[0], submitter[1]);
            }
            const headers = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Kraft-Form': 'true',
                'X-Kraft-Redirect': String(Boolean(navigation && navigation !== 'false')),
                'X-Kraft-Success-Message': String(form.dataset.successMessage !== 'false'),
            };
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            if (token) {
                headers['X-CSRF-TOKEN'] = token;
            }
            if (coreContentForm(form)) {
                const inert = form.inert;
                form.inert = true;
                restoreInert = () => {
                    form.inert = inert;
                };
            }
            sent = true;
            response = await confirmedFetch(
                form,
                button?.formAction && button.hasAttribute('formaction') ? button.formAction : form.action,
                {
                    method: button?.formMethod && button.hasAttribute('formmethod') ? button.formMethod : form.method,
                    body,
                    headers,
                    credentials: 'same-origin',
                    redirect: 'error',
                },
            );
            const attachment = response.headers?.get?.('Content-Disposition');
            if (response.ok && attachment?.toLowerCase().startsWith('attachment')) {
                const blob = await response.blob();
                const address = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = address;
                const encoded = attachment.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
                const plain = attachment.match(/filename="([^"]+)"|filename=([^;]+)/i);
                let filename = encoded ? decodeURIComponent(encoded) : plain?.[1] || plain?.[2] || 'download';
                filename = filename.replace(/[\\/\u0000-\u001f\u007f]/g, '_');
                link.download = filename;
                document.body.append(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(address), 60000);
                data = { message: configuration.messages.download_started || configuration.messages.saved };
                succeeded = true;
            } else {
                const envelope = await response.json().catch(() => null);
                const valid =
                    envelope &&
                    typeof envelope.success === 'boolean' &&
                    Object.hasOwn(envelope, 'data') &&
                    envelope.data &&
                    typeof envelope.data === 'object' &&
                    !Array.isArray(envelope.data);
                data = valid ? envelope.data : undefined;
                if (!response.ok || !valid || !envelope.success || Object.keys(data?.errors ?? {}).length) {
                    restoreInert();
                    await showErrors(form, data?.errors);
                    const fields = Object.values(data?.errors ?? {}).flat();
                    failure =
                        response.status === 419
                            ? configuration.messages.session_expired
                            : response.status >= 500 || !data
                              ? configuration.messages.error
                              : fields.length
                                ? fields
                                : data.message || configuration.messages.error;
                    throw new Error([failure].flat()[0]);
                }
                succeeded = true;
            }
            savedMediaForms.add(form);
        } catch (error) {
            restoreInert();
            if (!confirmationCancelled(error)) {
                message(
                    form,
                    'error',
                    failure ??
                        (error instanceof TypeError
                            ? configuration.messages.network_error
                            : configuration.messages[error.message] || error.message || configuration.messages.error),
                );
                form.dispatchEvent(
                    new CustomEvent('ajax:error', {
                        bubbles: true,
                        detail: { data, response, error, submitter: button },
                    }),
                );
            }
        } finally {
            restore();
            if (sent) {
                try {
                    window.KraftCaptcha?.reset(form);
                } catch {}
            }
            form.dispatchEvent(
                new CustomEvent('ajax:complete', { bubbles: true, detail: { data, response, submitter: button } }),
            );
        }
        try {
            if (!succeeded) {
                return;
            }
            closedModal = await closeActionModal(form);
            if (data.type === 'warning' || form.dataset.successMessage !== 'false') {
                const custom = data.type === 'warning' ? null : form.dataset.successMessage;
                message(
                    form,
                    data.type === 'warning' ? 'warning' : 'success',
                    custom && custom !== 'true' ? custom : (data.message ?? configuration.messages.saved),
                );
            }
            if (form.dataset.reset === 'true') {
                form.reset();
                editors(form).forEach((editor) => editor.setContent(editor.getElement().value));
            }
            const detail = { data, response, submitter: button };
            const proceed = form.dispatchEvent(
                new CustomEvent('ajax:success', { bubbles: true, cancelable: true, detail }),
            );
            const handler = callback(form.dataset.successCallback);
            if (form.dataset.successCallback && !handler) {
                throw new Error();
            }
            if (handler && (await handler.call(form, data, form, button)) === false) {
                return;
            }
            if (proceed && form.dataset.ajaxRefresh) {
                const region = document.getElementById(form.dataset.ajaxRefresh);
                if (!region?.hasAttribute('data-ajax-pagination')) {
                    throw new Error();
                }
                region.dispatchEvent(
                    new CustomEvent('pagination:navigate', {
                        bubbles: true,
                        detail: { url: window.location.href, push: false, scroll: false },
                    }),
                );
                return;
            }
            const destination = proceed && redirect(navigation, data, form);
            if (destination) {
                const delay = Number(form.dataset.redirectDelay ?? configuration.redirectDelay ?? 0);
                await new Promise((resolve) => setTimeout(resolve, Number.isFinite(delay) ? Math.max(0, delay) : 0));
                const current = new URL(window.location.href);
                const target = new URL(destination);
                const refresh =
                    target.hash &&
                    target.origin === current.origin &&
                    target.pathname === current.pathname &&
                    target.search === current.search;
                window.location.assign(destination);
                if (refresh) {
                    window.location.reload();
                }
            }
        } catch {
            message(form, 'error', configuration.messages.callback_error, { page: closedModal });
        } finally {
            restoreInert();
            pending.delete(form);
        }
    }

    configure(JSON.parse(document.querySelector('#kraft-form-config')?.textContent || '{}'));
    window.KraftForms = {
        configure,
        submit: (form, button) => {
            saveEditors(form);
            form.requestSubmit(button);
        },
    };

    document.addEventListener(
        'click',
        (event) => {
            const button = event.target.closest('button, input[type="submit"], input[type="image"]');
            if (button?.form?.dataset.ajax === 'true') {
                saveEditors(button.form);
            }
        },
        true,
    );

    document.addEventListener(
        'keydown',
        (event) => {
            if (event.key === 'Enter' && event.target.form?.dataset.ajax === 'true') {
                saveEditors(event.target.form);
            }
        },
        true,
    );

    document.addEventListener('submit', (event) => {
        const form = event.target;
        const button = event.submitter;
        const method = button?.hasAttribute('formmethod') ? button.formMethod : form.method;
        const target = button?.hasAttribute('formtarget') ? button.formTarget : form.target;
        if (target && target.toLowerCase() !== '_self') {
            return;
        }
        if (
            !event.defaultPrevented &&
            form.dataset.ajax === undefined &&
            form.querySelector?.('[data-sensitive-confirmation]') &&
            ['post', 'put', 'patch', 'delete'].includes(method.toLowerCase())
        ) {
            form.dataset.ajax = 'true';
            form.dataset.redirectUrl ??= 'response';
        }
        if (
            !event.defaultPrevented &&
            form.dataset.ajax === undefined &&
            form.querySelector?.('textarea[data-editor]') &&
            ['post', 'put', 'patch', 'delete'].includes(method.toLowerCase())
        ) {
            form.dataset.ajax = 'true';
            form.dataset.redirectUrl ??= 'response';
        }
        if (form.dataset.ajax === 'false' && mediaDrafts.hasReferencedMediaDraft(form)) {
            event.preventDefault();
            message(
                form,
                'error',
                configuration.messages.local_image_unavailable || configuration.messages.upload_error,
            );
            return;
        }
        if (
            event.defaultPrevented ||
            form.dataset.ajax !== 'true' ||
            !['post', 'put', 'patch', 'delete'].includes(method.toLowerCase())
        ) {
            return;
        }
        event.preventDefault();
        const action = new URL(
            button?.hasAttribute('formaction') ? button.formAction : form.action,
            window.location.href,
        );
        if (action.origin !== window.location.origin || action.username || action.password) {
            message(form, 'error', configuration.messages.error);
            return;
        }
        saveEditors(form);
        if (form.noValidate || button?.formNoValidate || form.reportValidity()) {
            void submit(form, button);
        }
    });
}

function initializeInstallation() {
    const wizard = document.querySelector('[data-install-wizard]');

    if (wizard) {
        const steps = [...wizard.querySelectorAll('[data-install-step]')];
        const navigation = [...document.querySelectorAll('[data-step-target]')];
        const controls = wizard.querySelector('[data-wizard-controls]');
        const previous = wizard.querySelector('[data-step-previous]');
        const next = wizard.querySelector('[data-step-next]');
        const submit = wizard.querySelector('[data-install-submit]');
        const status = document.querySelector('[data-step-status]');
        const progress = document.querySelector('.install-progress progress');
        const database = wizard.elements.namedItem('database');
        const databaseFields = wizard.querySelector('[data-database-fields]');
        const databasePort = wizard.elements.namedItem('db_port');
        const sqliteNote = wizard.querySelector('[data-sqlite-note]');
        const password = wizard.elements.namedItem('password');
        const confirmation = wizard.elements.namedItem('password_confirmation');
        const requirementsReady = wizard.dataset['requirementsReady'] === 'true';
        const requirementsIndex = steps.findIndex((step) => step.dataset['installStep'] === 'requirements');
        const languageIndex = steps.findIndex((step) => step.dataset['installStep'] === 'language');
        const language = wizard.querySelector('[data-install-language]');
        const theme = wizard.querySelector('[data-install-theme]');
        const packages = new Map(
            [...wizard.querySelectorAll('input[data-package-id]')].map((input) => [input.dataset['packageId'], input]),
        );
        let current = 0;
        let reached = 0;
        let pending = false;

        const configureExtensions = () => {
            packages.forEach((input) => input.setCustomValidity(''));
            theme?.setCustomValidity('');
            const visited = new Set();
            const requireDependencies = (control, source = control) => {
                if (!control || visited.has(control)) {
                    return;
                }
                visited.add(control);
                for (const id of JSON.parse(control.dataset['installRequires'] || '[]')) {
                    const plugin = packages.get(id);
                    if (plugin && !plugin.disabled) {
                        plugin.checked = true;
                        requireDependencies(plugin, source);
                        continue;
                    }
                    const option = [...(theme?.options || [])].find((item) => item.value === id);
                    if (option && !option.disabled && (!theme.value || theme.value === id)) {
                        theme.value = id;
                        requireDependencies(option, source);
                        continue;
                    }
                    if (wizard.querySelector('[name="game"]:checked')?.value !== id) {
                        source.setCustomValidity(
                            source.dataset['dependencyError'] || control.dataset['dependencyError'] || '',
                        );
                    }
                }
            };
            packages.forEach((input) => {
                if (input.checked && !input.disabled) {
                    requireDependencies(input);
                }
            });
            if (theme?.value) {
                requireDependencies(theme.selectedOptions[0], theme);
            }
        };

        const configureDatabase = () => {
            const defaultPort = database.selectedOptions[0]?.dataset.port ?? '';
            const usesDatabaseServer = defaultPort !== '';
            databasePort.placeholder = defaultPort;
            databaseFields.hidden = !usesDatabaseServer;
            sqliteNote.hidden = database.value !== 'sqlite';
            databaseFields.querySelectorAll('input').forEach((input) => {
                input.disabled = !usesDatabaseServer;
                input.required = usesDatabaseServer && input.hasAttribute('data-database-required');
            });
        };

        const matchPasswords = () => {
            confirmation.setCustomValidity(
                confirmation.value && confirmation.value !== password.value ? wizard.dataset['passwordMismatch'] : '',
            );
        };

        const summarize = () => {
            wizard.querySelectorAll('[data-summary]').forEach((target) => {
                const name = target.dataset.summary;
                const field = wizard.elements.namedItem(name);
                let value;
                if (name === 'game') {
                    value = wizard.querySelector('[name="game"]:checked')?.dataset['gameLabel'] ?? '';
                } else if (field instanceof HTMLSelectElement) {
                    value = field.selectedOptions[0]?.textContent ?? '';
                } else {
                    value = field?.value?.trim() ?? '';
                }
                target.textContent = value || wizard.dataset['emptyLabel'];
            });
        };

        const show = (index, focus = true) => {
            current = index;
            reached = Math.max(reached, index);
            steps.forEach((step, stepIndex) => {
                step.hidden = stepIndex !== current;
            });
            navigation.forEach((button, stepIndex) => {
                button.disabled = pending || stepIndex > reached;
                button.closest('li').classList.toggle('is-complete', stepIndex < current);
                if (stepIndex === current) {
                    button.setAttribute('aria-current', 'step');
                } else {
                    button.removeAttribute('aria-current');
                }
            });
            previous.hidden = current === 0;
            previous.disabled = pending;
            next.hidden = current === steps.length - 1;
            next.disabled = pending || (current === requirementsIndex && !requirementsReady);
            submit.hidden = current !== steps.length - 1;
            progress.value = current + 1;
            status.textContent = wizard.dataset['progressLabel'].replace(':current', String(current + 1));
            navigation[current]?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            summarize();
            if (focus) {
                steps[current].querySelector('[data-step-title]')?.focus({ preventScroll: true });
            }
        };

        const invalidField = (step) =>
            [...step.querySelectorAll('input, select, textarea')].find(
                (field) => !field.disabled && !field.checkValidity(),
            );

        const validate = (index) => {
            if (index === requirementsIndex && !requirementsReady) {
                show(requirementsIndex);
                return false;
            }
            configureDatabase();
            configureExtensions();
            matchPasswords();
            const invalid = invalidField(steps[index]);
            if (!invalid) {
                return true;
            }
            show(index, false);
            invalid.reportValidity();
            invalid.focus();
            return false;
        };

        previous.addEventListener('click', () => {
            if (!pending && current > 0) {
                show(current - 1);
            }
        });
        next.addEventListener('click', () => {
            if (!pending && validate(current)) {
                show(Math.min(current + 1, steps.length - 1));
            }
        });
        navigation.forEach((button, index) => {
            button.addEventListener('click', () => {
                if (!pending && (index <= current || validate(current))) {
                    show(index);
                }
            });
            button.addEventListener('keydown', (event) => {
                const enabled = navigation.filter((item) => !item.disabled);
                const position = enabled.indexOf(button);
                let target;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    target = enabled[Math.min(position + 1, enabled.length - 1)];
                }
                if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    target = enabled[Math.max(position - 1, 0)];
                }
                if (event.key === 'Home') {
                    target = enabled[0];
                }
                if (event.key === 'End') {
                    target = enabled.at(-1);
                }
                if (target) {
                    event.preventDefault();
                    target.focus();
                }
            });
        });

        wizard.addEventListener('input', () => {
            matchPasswords();
            summarize();
        });
        wizard.addEventListener('change', (event) => {
            if (event.target === language && current === languageIndex) {
                const destination = new URL(language.dataset['languageUrl'], window.location.href);
                destination.searchParams.set('locale', language.value);
                destination.searchParams.set('timezone', wizard.elements.namedItem('timezone').value);
                window.location.assign(destination.href);
                return;
            }
            configureDatabase();
            configureExtensions();
            summarize();
        });
        wizard.addEventListener('keydown', (event) => {
            if (
                event.key !== 'Enter' ||
                current === steps.length - 1 ||
                event.target.matches('textarea, button, select, [type="radio"]')
            ) {
                return;
            }
            event.preventDefault();
            if (!pending && validate(current)) {
                show(Math.min(current + 1, steps.length - 1));
            }
        });
        wizard.addEventListener('submit', (event) => {
            if (pending) {
                event.preventDefault();
                return;
            }
            if (current !== steps.length - 1) {
                event.preventDefault();
                if (validate(current)) {
                    show(Math.min(current + 1, steps.length - 1));
                }
                return;
            }
            for (let index = 0; index < steps.length; index += 1) {
                if (!validate(index)) {
                    event.preventDefault();
                    return;
                }
            }
            pending = true;
            wizard.setAttribute('aria-busy', 'true');
            navigation.forEach((button) => {
                button.disabled = true;
            });
            previous.disabled = true;
            next.disabled = true;
            submit.disabled = true;
            submit.textContent = wizard.dataset['pendingLabel'];
            status.textContent = wizard.dataset['pendingLabel'];
        });

        configureDatabase();
        configureExtensions();
        matchPasswords();
        wizard.noValidate = true;
        wizard.classList.add('is-enhanced');
        controls.hidden = false;
        let initial = 0;
        const errors = JSON.parse(wizard.dataset['errorFields'] || '[]');
        for (const name of errors) {
            const field =
                name === 'extensions' || name.startsWith('extensions.')
                    ? wizard.querySelector('[name="extensions[]"]')
                    : wizard.elements.namedItem(name);
            const control = field instanceof RadioNodeList ? [...field][0] : field;
            const index = steps.findIndex((step) => control && step.contains(control));
            if (index >= 0) {
                initial = index;
                break;
            }
        }
        reached = initial;
        show(initial, errors.length > 0);
    }
}

function initializeImports() {
    for (const form of document.querySelectorAll('[data-import-analyze]')) {
        const selection = form.querySelector('input[type="checkbox"][name="install_plugins"]');
        const codeFields = form.querySelector('[data-import-code]');
        if (!selection || !codeFields) {
            continue;
        }
        const update = () => {
            codeFields.hidden = !selection.checked;
            for (const field of codeFields.querySelectorAll('input')) {
                field.disabled = !selection.checked;
                field.required = selection.checked;
                if (!selection.checked) {
                    field.value = '';
                }
            }
        };
        selection.addEventListener('change', update);
        update();
    }

    for (const form of document.querySelectorAll('[data-import-execute]')) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"], button');
            const output = form.parentElement.querySelector('[data-import-progress]');
            const body = new FormData(form);
            const query = new URLSearchParams();
            const resume = body.get('resume');
            if (resume) {
                query.set('id', String(resume));
            } else {
                query.set('hash', String(body.get('hash')));
            }
            button.disabled = true;
            output.hidden = false;
            output.textContent = output.dataset.starting;
            let active = true;
            const poll = async () => {
                if (!active) {
                    return;
                }
                try {
                    const response = await fetch(`${form.dataset.progressUrl}?${query}`, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (response.ok) {
                        const envelope = await response.json();
                        if (envelope.success !== true || !envelope.data) {
                            throw new Error('Import progress unavailable');
                        }
                        const data = envelope.data;
                        const rows = Object.values(data.report?.mapped || {}).reduce(
                            (total, count) => total + count,
                            0,
                        );
                        const snapshots = Object.keys(data.report?.archives || {}).length;
                        const label =
                            output.dataset[`status${data.status?.replaceAll('_', '')}`] || output.dataset.starting;
                        output.textContent = `${label} · ${snapshots} ${output.dataset['tables']} · ${rows} ${output.dataset.rows}`;
                    }
                } catch {}
                if (active) {
                    setTimeout(poll, 1500);
                }
            };
            void poll();
            try {
                const response = await confirmedFetch(form, form.action, {
                    method: 'POST',
                    body,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const envelope = await response.json();
                const data = envelope.data;
                if (!response.ok || envelope.success !== true) {
                    throw new Error(
                        Object.values(data?.errors || {})
                            .flat()
                            .join(' ') || output.dataset.failed,
                    );
                }
                output.textContent = output.dataset.completed;
                location.reload();
            } catch (error) {
                if (confirmationCancelled(error)) {
                    output.hidden = true;
                    return;
                }
                output.textContent = error.message || output.dataset.failed;
                output.classList.remove('alert-secondary');
                output.classList.add('alert-danger');
            } finally {
                active = false;
                button.disabled = false;
            }
        });
    }
}

function initializeSliders() {
    document.querySelectorAll('[data-kraft-slider]').forEach((element) => {
        if (element.querySelectorAll('.carousel-item').length > 1) {
            Carousel.getOrCreateInstance(element, { interval: false, ride: false, keyboard: true, touch: true });
            element.addEventListener('slid.bs.carousel', () => {
                const status = element.querySelector('[data-slider-status]');
                if (status) {
                    status.textContent =
                        element.querySelector('.carousel-item.active')?.getAttribute('aria-label') ?? '';
                }
            });
        }
    });
}

function initializeWelcomeAlerts() {
    document.querySelectorAll('[data-welcome-alert]').forEach((element) => {
        const key = `kraft.welcome.${element.dataset['welcomeAlert']}`;
        try {
            if (sessionStorage.getItem(key)) {
                element.remove();
                return;
            }
        } catch {}
        element.addEventListener('closed.bs.alert', () => {
            try {
                sessionStorage.setItem(key, '1');
            } catch {}
        });
    });
}

function initializeSlugs(root = document) {
    if (typeof document !== 'undefined') {
        const selector = 'input[name="slug"]:not([type="hidden"]), input[data-slug], input[data-slug-source]';
        const inputs = [...(root.matches?.(selector) ? [root] : []), ...root.querySelectorAll(selector)];
        inputs.forEach((input) => {
            if (!initializeControl(input, 'slug')) {
                return;
            }
            const options = (final) => ({
                paths: input.dataset.slugPaths === 'true',
                final,
                max: input.maxLength > 0 ? Math.min(input.maxLength, 2000) : 160,
            });
            const normalize = (final) => {
                const settings = options(final);
                const start = input.selectionStart;
                const end = input.selectionEnd;
                const before = input.value;
                const value = normalizeSlug(before, settings);
                if (before === value) {
                    return;
                }
                input.value = value;
                if (
                    !final &&
                    start !== null &&
                    end !== null &&
                    ['text', 'search', 'tel', 'password', ''].includes(input.type)
                ) {
                    input.setSelectionRange(
                        Math.min(value.length, normalizeSlug(before.slice(0, start), settings).length),
                        Math.min(value.length, normalizeSlug(before.slice(0, end), settings).length),
                    );
                }
                if (final) {
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            };
            let manualSlug = input.value !== '';
            input.addEventListener('input', (event) => {
                manualSlug = true;
                if (!event.isComposing) {
                    normalize(false);
                }
            });
            input.addEventListener('compositionend', () => normalize(false));
            input.addEventListener('blur', () => normalize(true));
            const source = input.dataset.slugSource ? input.form?.querySelector(input.dataset.slugSource) : null;
            source?.addEventListener('input', () => {
                if (!manualSlug) {
                    input.value = normalizeSlug(source.value, options(true));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
    }
}

function initializeHashTabs(root = document) {
    const selector = '[data-hash-tabs]';
    const containers = [...(root.matches?.(selector) ? [root] : []), ...root.querySelectorAll(selector)];
    containers.forEach((container) => {
        if (!initializeControl(container, 'hash-tabs')) {
            return;
        }
        const tabs = [...container.querySelectorAll('[data-bs-toggle="tab"][data-bs-target]')];
        tabs.forEach((tab) => {
            tab.addEventListener('shown.bs.tab', () => {
                window.history.replaceState(window.history.state, '', tab.dataset.bsTarget);
            });
        });
        if (!JSON.parse(container.dataset.hashTabsErrors || '[]').length) {
            tabs.find((tab) => tab.dataset.bsTarget === window.location.hash)?.click();
        }
    });
}

function initializeDates(root = document) {
    root.querySelectorAll('[data-detect-timezone]').forEach((select) => {
        if (!initializeControl(select, 'timezone')) {
            return;
        }
        try {
            const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const options = [...select.options];
            const match =
                options.find((option) => option.value === zone) ??
                options.find((option) => {
                    try {
                        return (
                            Intl.DateTimeFormat('en', { timeZone: option.value }).resolvedOptions().timeZone === zone
                        );
                    } catch {
                        return false;
                    }
                });
            if (match) {
                select.value = match.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        } catch {}
    });

    root.querySelectorAll('[data-local-datetime]').forEach((container) => {
        if (!initializeControl(container, 'datetime')) {
            return;
        }
        const input = container.querySelector('input[type="datetime-local"]');
        const area = container.querySelector('[data-date-occurrence]');
        const select = area?.querySelector('select');
        const status = container.querySelector('[data-date-status]');
        if (!input || !area || !select || !status) {
            return;
        }
        let timer;
        let request;
        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            request?.abort();
            select.value = '';
            select.required = false;
            area.hidden = true;
            status.textContent = '';
            input.setCustomValidity('');
            if (!input.value || !input.validity.valid) {
                return;
            }
            timer = window.setTimeout(async () => {
                const current = input.value;
                const controller = new AbortController();
                request = controller;
                try {
                    const endpoint = new URL(container.dataset['dateEndpoint'], window.location.origin);
                    if (endpoint.origin !== window.location.origin) {
                        return;
                    }
                    endpoint.searchParams.set('value', current);
                    endpoint.searchParams.set('timezone', container.dataset['zone']);
                    const response = await fetch(endpoint, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    });
                    const envelope = await response.json();
                    if (input.value !== current) {
                        return;
                    }
                    if (!response.ok || envelope.success !== true || !envelope.data) {
                        throw new Error('Date check unavailable');
                    }
                    const result = envelope.data;
                    select.replaceChildren(new Option(container.dataset['choose'], ''));
                    const ambiguous = result.choices.length > 1;
                    for (const choice of ambiguous ? result.choices : []) {
                        select.add(new Option(choice.label, choice.choice));
                    }
                    area.hidden = !ambiguous;
                    select.required = ambiguous;
                    status.textContent = result.message || '';
                    if (!result.choices.length) {
                        input.setCustomValidity(result.message || container.dataset['unavailable']);
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        status.textContent = container.dataset['unavailable'];
                    }
                }
            }, 400);
        });
    });
}

export function initializeAvatarEditors(root = document) {
    root.querySelectorAll('[data-avatar-form]').forEach((form) => {
        if (!initializeControl(form, 'avatar')) {
            return;
        }
        const input = form.querySelector('[data-avatar-file]');
        const preview = form.querySelector('[data-avatar-preview]');
        const save = form.querySelector('[data-avatar-save]');
        if (!input || !preview || !save) {
            return;
        }
        const original = preview.getAttribute('src');
        let previewUrl = null;
        const release = () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = null;
            }
        };
        const reset = () => {
            release();
            input.value = '';
            preview.src = original;
            save.disabled = true;
        };
        input.addEventListener('change', () => {
            release();
            const file = input.files?.[0];
            previewUrl = file ? URL.createObjectURL(file) : null;
            preview.src = previewUrl ?? original;
            save.disabled = !file;
        });
        form.addEventListener('reset', reset);
        form.closest('.modal')?.addEventListener('hidden.bs.modal', reset);
        save.disabled = !input.files?.length;
    });
}

initializeCaptchas();
initializeForms();
initializeInstallation();
initializeImports();
initializeSliders();
initializeWelcomeAlerts();
initializeSlugs();
initializeHashTabs();
initializeDates();
initializeAvatarEditors();

const root = document.documentElement;
if (root.dataset.appearance === 'auto') {
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const update = () => {
        root.dataset.bsTheme = media.matches ? 'dark' : 'light';
    };
    media.addEventListener('change', update);
    update();
}

function initializeModals(root = document) {
    root.querySelectorAll('.modal').forEach((modal) => {
        if (!initializeControl(modal, 'modal')) {
            return;
        }
        modal.addEventListener('shown.bs.modal', () => {
            const field =
                modal.querySelector('[data-modal-errors]') ??
                modal.querySelector('[autofocus], .form-control:not([disabled]), .form-select:not([disabled])');
            field?.focus();
            window.dispatchEvent(new Event('resize'));
        });
        modal.addEventListener('hidden.bs.modal', () => {
            modal.querySelectorAll('input[type="password"], input[name="code"]').forEach((field) => {
                field.value = '';
            });
        });
    });
}

initializeModals();

const reopened = document.querySelector('[data-kraft-modal-open]');
if (reopened) {
    Modal.getOrCreateInstance(reopened).show();
}

const siteMenu = document.querySelector('#site-menu');
if (siteMenu) {
    siteMenu.classList.add('collapse');
    const toggle = document.querySelector('[data-site-toggle]');
    if (toggle) {
        toggle.hidden = false;
    }
}

document.querySelectorAll('[data-profile-form]').forEach((form) => {
    const email = form.querySelector('[data-profile-email]');
    const credentials = form.querySelectorAll('[data-profile-confirmation-field]');
    const confirmation = form.querySelector('[data-profile-confirmation]');
    if (!email || !credentials.length) {
        return;
    }
    const updateEmailConfirmation = () => {
        const changed = email.value.trim().toLowerCase() !== form.dataset.originalEmail.toLowerCase();
        credentials.forEach((field) => {
            field.required = changed;
            field.disabled = !changed;
        });
        if (confirmation) {
            confirmation.hidden = !changed;
        }
    };
    email.addEventListener('input', updateEmailConfirmation);
    updateEmailConfirmation();
});

document.querySelector('[data-menu-toggle]')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    const expanded = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(expanded));
    document.querySelector('#site-menu')?.classList.toggle('is-open', expanded);
});

async function copyText(value) {
    if (window.isSecureContext && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        } catch {}
    }
    const input = document.createElement('textarea');
    input.value = value;
    input.readOnly = true;
    input.setAttribute('aria-hidden', 'true');
    Object.assign(input.style, { position: 'fixed', left: '0', top: '0', width: '1px', height: '1px', opacity: '0' });
    const focus = document.activeElement;
    document.body.append(input);
    input.select();
    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        input.remove();
        focus?.focus({ preventScroll: true });
    }
}

function initializeCopyButtons(root = document) {
    root.querySelectorAll('[data-copy]').forEach((button) => {
        if (!initializeControl(button, 'copy')) {
            return;
        }
        let original = null;
        let timer;
        button.addEventListener('click', async () => {
            if (await copyText(button.dataset.copy)) {
                original ??= Array.from(button.childNodes);
                button.replaceChildren(document.createTextNode(button.dataset['copied']));
                clearTimeout(timer);
                timer = setTimeout(() => {
                    button.replaceChildren(...original);
                    original = null;
                }, 2000);
            } else {
                let input = button.parentElement.querySelector('[data-copy-fallback]');
                if (!input) {
                    input = document.createElement('input');
                    input.className = 'form-control form-control-sm mt-2';
                    input.readOnly = true;
                    input.dataset.copyFallback = '';
                    input.setAttribute('aria-label', button.getAttribute('aria-label') ?? button.textContent.trim());
                    button.after(input);
                }
                input.value = button.dataset.copy;
                input.focus();
                input.select();
            }
        });
    });
}

initializeCopyButtons();

document.addEventListener(
    'submit',
    (event) => {
        if (event.target.dataset.confirm && !window.confirm(event.target.dataset.confirm)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    },
    true,
);

let editorRequested = false;

function initializeRichEditors() {
    if (editorRequested || !document.querySelector('textarea[data-editor]')) {
        return;
    }
    editorRequested = true;
    const editor = document.createElement('script');
    const editorBase = document.querySelector('meta[name="kraft-editor-url"]')?.content ?? '/vendor/tinymce';
    editor.src = editorBase + '/tinymce.min.js';
    editor.addEventListener('load', () => {
        const appearance = createEditorAppearance(editorBase);
        const initialize = (field) => {
            if (field.dataset.editorReady || !field.getClientRects().length) {
                return;
            }
            field.dataset.editorReady = 'true';
            const editorLanguage =
                { fr: 'fr-FR', de: 'de', es: 'es', pt: 'pt-PT' }[document.documentElement.lang] ?? 'en';
            const initialization = appearance.ready.then(() =>
                window.tinymce.init({
                    target: field,
                    license_key: 'gpl',
                    base_url: editorBase,
                    language: editorLanguage,
                    language_url: editorLanguage === 'en' ? undefined : editorBase + '/langs/' + editorLanguage + '.js',
                    suffix: '.min',
                    skin: false,
                    content_css: false,
                    menubar: false,
                    height: 380,
                    plugins: 'autolink lists link image table code wordcount fullscreen',
                    toolbar:
                        'undo redo | blocks | bold italic | bullist numlist | link image table | removeformat code fullscreen',
                    branding: false,
                    promotion: false,
                    relative_urls: false,
                    convert_urls: false,
                    automatic_uploads: false,
                    images_replace_blob_uris: false,
                    images_upload_handler: async (blobInfo) => {
                        const owner = field.form;
                        if (!owner) {
                            throw new Error(
                                mediaDraftMessages.local_image_unavailable || mediaDraftMessages.upload_error,
                            );
                        }
                        if (coreContentForm(owner) || !savingEditorForms.has(owner)) {
                            try {
                                return mediaDrafts.stageMedia(owner, blobInfo.blob(), {
                                    name: blobInfo.filename(),
                                    url: blobInfo.blobUri(),
                                    field,
                                }).url;
                            } catch (error) {
                                throw new Error(mediaDraftMessages[error.message] || mediaDraftMessages.upload_error);
                            }
                        }
                        const body = new FormData();
                        body.append('file', blobInfo.blob(), blobInfo.filename());
                        const upload = new URL(
                            field.dataset.editorUploadUrl ??
                                document.querySelector('meta[name="kraft-media-url"]')?.content ??
                                '/admin/media',
                            window.location.href,
                        );
                        if (upload.origin !== window.location.origin || upload.username || upload.password) {
                            throw new Error('local_image_unavailable');
                        }
                        const response = await fetch(upload.href, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body,
                        });
                        const envelope = await response.json();
                        if (!response.ok || envelope.success !== true || !envelope.data?.location) {
                            throw new Error(envelope.data?.message || mediaDraftMessages.upload_error);
                        }
                        return envelope.data.location;
                    },
                    setup: (instance) => {
                        appearance.apply(instance);
                        instance.on('input change undo redo SetContent PastePostProcess', () => {
                            instance.save();
                            void captureEditorMedia(instance).catch(() => {});
                            field.dispatchEvent(new CustomEvent('editor:change', { bubbles: true }));
                        });
                    },
                }),
            );
            const firstInitialization = !editorInitializations.has(field);
            editorInitializations.set(
                field,
                Promise.resolve(initialization).catch(() => {
                    window.tinymce.get(field.id)?.remove();
                    delete field.dataset.editorReady;
                }),
            );
            if (firstInitialization) {
                let feedback = null;
                const invalidTargets = new Map();
                const feedbackId = 'kraft-editor-error-' + field.id;
                const clearEditorFeedback = () => {
                    feedback?.remove();
                    feedback = null;
                    for (const [target, previous] of invalidTargets) {
                        const describedBy = (target.getAttribute('aria-describedby') ?? '')
                            .split(/\s+/)
                            .filter((id) => id && id !== feedbackId)
                            .join(' ');
                        describedBy
                            ? target.setAttribute('aria-describedby', describedBy)
                            : target.removeAttribute('aria-describedby');
                        if (!target.classList.contains('is-invalid')) {
                            previous === null
                                ? target.removeAttribute('aria-invalid')
                                : target.setAttribute('aria-invalid', previous);
                        }
                    }
                    invalidTargets.clear();
                };
                field.addEventListener('input', clearEditorFeedback);
                field.addEventListener('editor:change', clearEditorFeedback);
                field.addEventListener('invalid', async (event) => {
                    const instance = window.tinymce.get(field.id);
                    if (instance) {
                        event.preventDefault();
                        await editorInitializations.get(field);
                        const ready = window.tinymce.get(field.id);
                        clearEditorFeedback();
                        if (!field.validationMessage) {
                            return;
                        }
                        const container = ready?.getContainer?.();
                        feedback = document.createElement('div');
                        feedback.id = feedbackId;
                        feedback.dataset.editorValidation = '';
                        feedback.className = 'invalid-feedback d-block';
                        feedback.setAttribute('role', 'alert');
                        feedback.textContent = field.validationMessage;
                        (container ?? field).after(feedback);
                        for (const target of [field, container?.querySelector('iframe') ?? container].filter(Boolean)) {
                            invalidTargets.set(target, target.getAttribute('aria-invalid'));
                            target.setAttribute('aria-invalid', 'true');
                            target.setAttribute(
                                'aria-describedby',
                                [target.getAttribute('aria-describedby'), feedbackId].filter(Boolean).join(' '),
                            );
                        }
                        ready && ready.initialized !== false ? ready.focus() : field.focus();
                    }
                });
            }
        };
        const initializeVisibleEditors = () => document.querySelectorAll('textarea[data-editor]').forEach(initialize);
        initializeVisibleEditors();
        for (const event of ['shown.bs.modal', 'shown.bs.tab', 'shown.bs.collapse', 'kraft:content-added']) {
            document.addEventListener(event, initializeVisibleEditors);
        }
    });
    editor.addEventListener('error', () => {
        editorRequested = false;
    });
    document.head.append(editor);
}

initializeRichEditors();

document.addEventListener('kraft:content-added', (event) => {
    const root = event.target;
    initializeSlugs(root);
    initializeHashTabs(root);
    initializeDates(root);
    initializeAvatarEditors(root);
    initializeModals(root);
    initializeCopyButtons(root);
    initializeRichEditors();
    void window.KraftCaptcha?.render(root);
});

document.addEventListener('pagination:before', (event) => {
    const root = event.target;
    for (const editor of window.tinymce?.get() ?? []) {
        if (root.contains(editor.getElement())) {
            normalizeEditorMedia(editor);
            editor.save();
            const scan = editorMediaScans.get(editor);
            if (scan) {
                event.detail?.waitUntil?.(scan.then(() => editor.save()));
            }
        }
    }
});

document.addEventListener('media-draft:change', (event) => savedMediaForms.delete(event.target));

window.addEventListener('beforeunload', (event) => {
    if (pendingMediaForms().length) {
        event.preventDefault();
        event.returnValue = '';
    }
});

document.addEventListener('pagination:replace', (event) => {
    const root = event.target;
    for (const editor of [...(window.tinymce?.get() ?? [])]) {
        const field = editor.getElement();
        if (root.contains(field)) {
            normalizeEditorMedia(editor);
            editor.save();
            editor.remove();
            delete field.dataset.editorReady;
        }
    }
});
