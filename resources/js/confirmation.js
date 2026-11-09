import Modal from 'bootstrap/js/dist/modal';

export class ConfirmationCancelled extends Error {
    constructor() {
        super('confirmation_cancelled');
        this.name = 'ConfirmationCancelled';
    }
}

export function isConfirmationCancelled(error) {
    return error?.name === 'ConfirmationCancelled';
}

async function confirmationChallenge(response) {
    if (response.status !== 428) {
        return null;
    }
    const envelope = await response
        .clone()
        .json()
        .catch(() => null);
    const challenge = envelope?.data?.confirmation;
    if (
        envelope?.success !== false ||
        !challenge ||
        typeof challenge.password !== 'boolean' ||
        typeof challenge.two_factor !== 'boolean' ||
        typeof challenge.reauthenticate !== 'boolean' ||
        typeof challenge.password_field !== 'string' ||
        !/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/.test(challenge.password_field)
    ) {
        return null;
    }
    return { ...challenge, errors: envelope.data.errors ?? {} };
}

async function credentialRequest(request, challenge, credentials) {
    try {
        const headers = new Headers(request.headers);
        let body;
        if (headers.get('Content-Type')?.includes('application/json')) {
            const data = await request.clone().json();
            if (!data || typeof data !== 'object' || Array.isArray(data)) {
                throw new TypeError('Invalid confirmation request body.');
            }
            body = JSON.stringify({
                ...data,
                ...(challenge.password ? { [challenge.password_field]: credentials.password } : {}),
                ...(challenge.two_factor ? { code: credentials.code } : {}),
            });
        } else {
            body = request.body ? await request.clone().formData() : new FormData();
            if (challenge.password) {
                body.set(challenge.password_field, credentials.password);
            }
            if (challenge.two_factor) {
                body.set('code', credentials.code);
            }
            headers.delete('Content-Type');
        }
        return new Request(request.clone(), { headers, body });
    } finally {
        credentials.password = '';
        credentials.code = '';
    }
}

export function createConfirmedFetch({
    origin,
    apiPath = '/api',
    fetchRequest,
    requestConfirmation,
    finishConfirmation,
}) {
    let active = null;

    return async function confirmedFetch(form, input, options = {}) {
        const source = input instanceof Request ? input.clone() : new URL(input, origin).href;
        const request = new Request(source, options);
        const destination = new URL(request.url);
        if (
            destination.origin !== origin ||
            ['GET', 'HEAD'].includes(request.method) ||
            destination.pathname === apiPath ||
            destination.pathname.startsWith(apiPath + '/')
        ) {
            return fetchRequest(request);
        }
        const headers = new Headers(request.headers);
        headers.set('X-Kraft-Confirmation', 'modal');
        if (!headers.has('Accept')) {
            headers.set('Accept', 'application/json');
        }
        const snapshot = new Request(request, { headers });
        let owned = false;
        try {
            let response = await fetchRequest(snapshot.clone());
            let challenge = await confirmationChallenge(response);
            while (challenge) {
                if (!owned) {
                    if (active) {
                        throw new ConfirmationCancelled();
                    }
                    active = true;
                    owned = true;
                }
                if (snapshot.signal.aborted) {
                    throw new ConfirmationCancelled();
                }
                const credentials = await requestConfirmation(challenge, form, snapshot.signal);
                if (!credentials) {
                    throw new ConfirmationCancelled();
                }
                const retry = await credentialRequest(snapshot, challenge, credentials);
                response = await fetchRequest(retry);
                challenge = await confirmationChallenge(response);
            }
            return response;
        } finally {
            if (owned) {
                try {
                    await finishConfirmation(form);
                } finally {
                    active = null;
                }
            }
        }
    };
}

function createConfirmationDialog(document, browser) {
    let element;
    let modal;
    let session;
    let pending;
    let busy = false;
    let closing = false;
    let hiding;

    const labels = () =>
        JSON.parse(document.querySelector('#kraft-form-config')?.textContent || '{}').confirmation || {};
    const field = (name) => element.querySelector(`[data-confirmation-${name}]`);

    const settleAnimations = async (target) => {
        const animated = [target, target.querySelector('.modal-dialog')].filter(Boolean);
        await Promise.all(
            animated
                .flatMap((item) => item.getAnimations?.() ?? [])
                .map((animation) => animation.finished.catch(() => {})),
        );
    };

    const transition = async (target, name, action) => {
        await settleAnimations(target);
        const opening = name === 'shown.bs.modal';
        if (opening && target.classList.contains('show')) {
            return;
        }
        await new Promise((resolve, reject) => {
            const started = opening ? 'show.bs.modal' : 'hide.bs.modal';
            const complete = () => {
                target.removeEventListener(started, begin);
                resolve();
            };
            const begin = (event) => {
                Promise.resolve().then(() => {
                    if (event.defaultPrevented) {
                        target.removeEventListener(name, complete);
                        reject(new ConfirmationCancelled());
                    }
                });
            };
            target.addEventListener(started, begin, { once: true });
            target.addEventListener(name, complete, { once: true });
            action();
        });
    };

    const disableControls = (value) => {
        element.querySelectorAll('input, button').forEach((input) => {
            input.disabled = value;
        });
    };

    const setBusy = (value) => {
        busy = value;
        element.setAttribute('aria-busy', String(value));
        disableControls(value);
        field('submit').textContent = value ? labels().loading : labels().confirm;
    };

    const clear = () => {
        field('password').value = '';
        field('code').value = '';
    };

    const cancel = () => {
        if (pending) {
            const current = pending;
            pending = null;
            current.reject(new ConfirmationCancelled());
        }
    };

    const create = () => {
        if (element) {
            return;
        }
        element = document.createElement('div');
        element.className = 'modal fade';
        element.id = 'kraft-confirmation-modal';
        element.tabIndex = -1;
        element.setAttribute('aria-labelledby', 'kraft-confirmation-title');
        element.setAttribute('aria-hidden', 'true');
        element.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" data-ajax="false">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="kraft-confirmation-title" data-confirmation-title></h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" data-confirmation-cancel></button>
                    </div>
                    <div class="modal-body">
                        <p data-confirmation-help></p>
                        <div class="alert alert-danger" role="alert" tabindex="-1" data-confirmation-errors hidden></div>
                        <div class="mb-3" data-confirmation-password-group>
                            <label class="form-label" for="kraft-confirmation-password" data-confirmation-password-label></label>
                            <input class="form-control" id="kraft-confirmation-password" type="password" autocomplete="current-password" data-confirmation-password>
                        </div>
                        <div data-confirmation-code-group>
                            <label class="form-label" for="kraft-confirmation-code" data-confirmation-code-label></label>
                            <input class="form-control" id="kraft-confirmation-code" autocomplete="one-time-code" maxlength="32" aria-describedby="kraft-confirmation-code-help" data-confirmation-code>
                            <div class="form-text" id="kraft-confirmation-code-help" data-confirmation-code-help></div>
                        </div>
                        <a class="btn btn-outline-primary" data-confirmation-signin hidden></a>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal" data-confirmation-cancel-label></button>
                        <button class="btn btn-primary" type="submit" data-confirmation-submit></button>
                    </div>
                </form>
            </div>`;
        document.body.append(element);
        modal = new Modal(element);
        element.addEventListener('hide.bs.modal', (event) => {
            if (busy && !closing) {
                event.preventDefault();
                return;
            }
            hiding = new Promise((resolve) => {
                element.addEventListener(
                    'hidden.bs.modal',
                    () => {
                        hiding = null;
                        resolve();
                    },
                    { once: true },
                );
            });
            if (!closing) {
                cancel();
            }
        });
        element.querySelector('form').addEventListener('submit', (event) => {
            event.preventDefault();
            if (!pending || busy || !event.currentTarget.reportValidity()) {
                return;
            }
            const current = pending;
            pending = null;
            const credentials = { password: field('password').value, code: field('code').value };
            clear();
            setBusy(true);
            current.resolve(credentials);
        });
    };

    const open = async (form) => {
        if (session) {
            return;
        }
        session = { form, parent: form?.closest('.modal.show'), focus: document.activeElement };
        disableControls(true);
        try {
            if (session.parent) {
                await transition(session.parent, 'hidden.bs.modal', () =>
                    Modal.getOrCreateInstance(session.parent).hide(),
                );
            }
            await transition(element, 'shown.bs.modal', () => modal.show());
        } finally {
            disableControls(busy);
        }
    };

    return {
        async request(challenge, form, signal) {
            create();
            const text = labels();
            setBusy(false);
            clear();
            field('title').textContent = text.title;
            field('help').textContent = challenge.reauthenticate ? text.reauthenticate : text.help;
            field('password-label').textContent = text.password;
            field('code-label').textContent = text.code;
            field('code-help').textContent = text.code_help;
            field('cancel').setAttribute('aria-label', text.cancel);
            field('cancel-label').textContent = text.cancel;
            field('submit').textContent = text.confirm;
            for (const [name, required] of [
                ['password', challenge.password],
                ['code', challenge.two_factor],
            ]) {
                field(name + '-group').hidden = challenge.reauthenticate || !required;
                field(name).required = !challenge.reauthenticate && required;
            }
            field('submit').hidden = challenge.reauthenticate;
            const errors = Object.values(challenge.errors)
                .flat()
                .filter((value) => typeof value === 'string');
            field('errors').textContent = errors.join(' ');
            field('errors').hidden = errors.length === 0;
            const signin = field('signin');
            signin.hidden = true;
            if (challenge.reauthenticate && typeof text.signin_url === 'string') {
                const destination = new URL(text.signin_url, browser.location.href);
                if (destination.origin === browser.location.origin && !destination.username && !destination.password) {
                    signin.href = destination.href;
                    signin.textContent = text.signin;
                    signin.hidden = false;
                }
            }
            const waiting = new Promise((resolve, reject) => {
                const abort = () => cancel();
                signal.addEventListener('abort', abort, { once: true });
                pending = {
                    resolve: (value) => {
                        signal.removeEventListener('abort', abort);
                        resolve(value);
                    },
                    reject: (error) => {
                        signal.removeEventListener('abort', abort);
                        reject(error);
                    },
                };
            });
            waiting.catch(() => {});
            await open(form);
            if (signal.aborted) {
                cancel();
            }
            if (pending) {
                if (challenge.password && !challenge.reauthenticate) {
                    field('password').focus();
                } else if (challenge.two_factor && !challenge.reauthenticate) {
                    field('code').focus();
                } else {
                    field('cancel-label').focus();
                }
            }
            return waiting;
        },
        async finish() {
            if (!session) {
                return;
            }
            const previous = session;
            session = null;
            cancel();
            clear();
            closing = true;
            setBusy(false);
            if (element.classList.contains('show')) {
                await transition(element, 'hidden.bs.modal', () => modal.hide());
            } else if (hiding) {
                await hiding;
            }
            closing = false;
            if (previous.parent?.isConnected && previous.form?.isConnected) {
                await transition(previous.parent, 'shown.bs.modal', () =>
                    Modal.getOrCreateInstance(previous.parent).show(),
                );
            }
            if (previous.focus?.isConnected) {
                previous.focus.focus({ preventScroll: true });
            }
        },
    };
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    const dialog = createConfirmationDialog(document, window);
    window.KraftConfirmation = {
        fetch: createConfirmedFetch({
            origin: window.location.origin,
            apiPath:
                JSON.parse(document.querySelector('#kraft-form-config')?.textContent || '{}').confirmation?.api_path ??
                '/api',
            fetchRequest: (request) => window.fetch(request),
            requestConfirmation: (challenge, form, signal) => dialog.request(challenge, form, signal),
            finishConfirmation: () => dialog.finish(),
        }),
        isCancelled: isConfirmationCancelled,
    };
}
