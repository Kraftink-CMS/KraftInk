import { continueAuthDialog } from './auth-dialog.js';

const formSelector = '[data-passkey-login], [data-passkey-create]';
const identifierSelector = 'input[autocomplete~="webauthn"]';
let active = null;

function decode(value) {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
}

function encode(value) {
    return btoa(String.fromCharCode(...new Uint8Array(value)))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

function credentialOptions(options, create) {
    const parser = create
        ? PublicKeyCredential.parseCreationOptionsFromJSON
        : PublicKeyCredential.parseRequestOptionsFromJSON;
    if (typeof parser === 'function') {
        return parser.call(PublicKeyCredential, options);
    }
    options.challenge = decode(options.challenge);
    if (options.user) {
        options.user.id = decode(options.user.id);
    }
    for (const descriptor of [...(options.excludeCredentials ?? []), ...(options.allowCredentials ?? [])]) {
        descriptor.id = decode(descriptor.id);
    }

    return options;
}

function credentialJSON(credential) {
    if (typeof credential.toJSON === 'function') {
        return JSON.stringify(credential.toJSON());
    }
    const response = credential.response;
    const data = { clientDataJSON: encode(response.clientDataJSON) };
    if (response.attestationObject) {
        data.attestationObject = encode(response.attestationObject);
        data.transports = response.getTransports?.() ?? [];
    } else {
        data.authenticatorData = encode(response.authenticatorData);
        data.signature = encode(response.signature);
        data.userHandle = response.userHandle ? encode(response.userHandle) : null;
    }

    return JSON.stringify({
        id: credential.id,
        rawId: encode(credential.rawId),
        type: credential.type,
        response: data,
        clientExtensionResults: credential.getClientExtensionResults(),
    });
}

async function request(form, url, data, signal, confirm = false) {
    const options = {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
            'X-Kraft-Form': 'true',
            'X-Kraft-Redirect': 'true',
        },
        body: JSON.stringify(data),
        signal,
    };
    const response = confirm && window.KraftConfirmation
        ? await window.KraftConfirmation.fetch(form, url, options)
        : await fetch(url, options);
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
        const message = Object.values(payload?.data?.errors ?? {}).flat().find((item) => typeof item === 'string');
        throw new Error(message || form.dataset.error);
    }

    return payload.data;
}

function supported() {
    return window.isSecureContext && Boolean(window.PublicKeyCredential && navigator.credentials);
}

function showControls() {
    if (!supported()) {
        return;
    }
    document.querySelectorAll(formSelector).forEach((form) => { form.hidden = false; });
}

async function run(form, conditional = false) {
    if (conditional && active) {
        return;
    }
    active?.controller.abort();
    const controller = new AbortController();
    const state = { controller, form, conditional };
    active = state;
    const { signal } = controller;
    const create = form.hasAttribute('data-passkey-create');
    const error = form.querySelector('[data-passkey-error]');
    const submit = form.querySelector('[type="submit"]');
    error.hidden = true;
    if (!conditional) {
        submit.disabled = true;
        form.setAttribute('aria-busy', 'true');
    }
    const deadline = conditional ? window.setTimeout(() => controller.abort(), 160000) : null;
    try {
        const { publicKey } = await request(form, form.dataset.options, {}, signal, create);
        const options = { publicKey: credentialOptions(publicKey, create), signal };
        if (conditional) {
            options.mediation = 'conditional';
        }
        const credential = create ? await navigator.credentials.create(options) : await navigator.credentials.get(options);
        if (!credential || signal.aborted) {
            return;
        }
        const data = { credentials: credentialJSON(credential) };
        if (create) {
            data.name = form.elements.name.value;
        }
        const result = await request(form, form.action, data, signal);
        if (typeof result?.redirect_url !== 'string') {
            throw new Error(form.dataset.error);
        }
        const destination = new URL(result.redirect_url, window.location.href);
        if (destination.origin !== window.location.origin) {
            throw new Error(form.dataset.error);
        }
        if (!continueAuthDialog(form, destination.href)) {
            window.location.assign(destination);
        }
    } catch (failure) {
        if (!conditional && !signal.aborted && !window.KraftConfirmation?.isCancelled(failure) && failure.name !== 'NotAllowedError') {
            error.textContent = failure.constructor === Error ? failure.message : form.dataset.error;
            error.hidden = false;
        }
    } finally {
        window.clearTimeout(deadline);
        if (!conditional && (active === state || active?.form !== form)) {
            submit.disabled = false;
            form.removeAttribute('aria-busy');
        }
        if (active === state) {
            active = null;
        }
    }
}

async function conditionalLogin() {
    if (
        !supported() || active || typeof PublicKeyCredential.isConditionalMediationAvailable !== 'function' ||
        !(await PublicKeyCredential.isConditionalMediationAvailable().catch(() => false))
    ) {
        return;
    }
    const input = [...document.querySelectorAll(identifierSelector)].find((field) => field.getClientRects().length > 0);
    const form = [...document.querySelectorAll('[data-passkey-login]')].find((candidate) => candidate.getClientRects().length > 0);
    if (input && form) {
        void run(form, true);
    }
}

document.addEventListener('submit', (event) => {
    if (event.target.matches(formSelector) && supported()) {
        event.preventDefault();
        void run(event.target);
    }
});
document.addEventListener('DOMContentLoaded', () => {
    showControls();
    void conditionalLogin();
});
document.addEventListener('kraft:content-added', () => showControls());
document.addEventListener('shown.bs.modal', () => {
    showControls();
    void conditionalLogin();
});
document.addEventListener('hide.bs.modal', (event) => {
    if (active && event.target.contains(active.form)) {
        active.controller.abort();
    }
});
document.addEventListener('focusin', (event) => {
    if (event.target.matches(identifierSelector)) {
        void conditionalLogin();
    }
});
