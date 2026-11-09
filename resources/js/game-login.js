import { continueAuthDialog } from './auth-dialog.js';

const initialized = new WeakSet();

class GameLoginError extends Error {
    constructor(message, fields = {}) {
        super(message);
        this.fields = fields;
    }
}

async function gameRequest(url, data, signal, finish = false) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    };
    if (finish) {
        headers['X-Kraft-Form'] = 'true';
        headers['X-Kraft-Redirect'] = 'true';
    }
    const response = await fetch(url, {
        method: data ? 'POST' : 'GET',
        body: data,
        headers,
        cache: 'no-store',
        signal,
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
        throw new GameLoginError(payload?.data?.message ?? payload?.message ?? '', payload?.data?.errors);
    }
    return payload.data;
}

function initializeGameLogin(root) {
    const step = root.querySelector('[data-game-login-step]');
    const finish = step?.querySelector('[data-game-login-finish]');
    if (initialized.has(root) || !finish) {
        return;
    }
    initialized.add(root);
    const copy = JSON.parse(root.dataset.gameCopy);
    const container = root.closest('[data-login-options]');
    const shell = root.closest('[data-auth-shell]');
    const intro = shell?.querySelector('[data-auth-step-intro]');
    const alternatives = container.querySelector('[data-game-login-alternatives]');
    const password = container.querySelector('[data-password-login]');
    const toggle = container.querySelector('[data-login-switch]');
    const start = root.querySelector('[data-game-login-start]');
    const stepTitle = shell?.querySelector('[data-auth-step-title]');
    const initialTitle = stepTitle?.textContent;
    const status = step.querySelector('[data-game-login-status]');
    const number = step.querySelector('[data-game-login-number]');
    const waiting = step.querySelector('[data-game-login-waiting]');
    const actions = step.querySelector('[data-game-login-actions]');
    const resend = step.querySelector('[data-game-login-resend]');
    const email = finish.elements.namedItem('email');
    const finishButton = finish.querySelector('button[type="submit"]');
    const startError = start.querySelector('[data-game-login-error]');
    const stepError = step.querySelector('[data-game-login-step-error]');
    let flow;
    let challenge;
    let pollTimer;
    let countdown;
    let resendAt = 0;
    let expiresAt = 0;
    let busy = false;
    let revision = 0;

    function showError(target, message = '') {
        target.textContent = message;
        target.hidden = !message;
    }

    function setTitle(value) {
        if (stepTitle && stepTitle.textContent !== value) {
            stepTitle.textContent = value;
        }
    }

    function showStep(active) {
        step.hidden = !active;
        start.hidden = active;
        if (intro) {
            intro.hidden = active;
        }
        if (alternatives) {
            alternatives.hidden = active;
        }
        if (!active) {
            setTitle(initialTitle);
        }
    }

    function errorMessage(error) {
        return error instanceof GameLoginError && error.message ? error.message : copy.error;
    }

    function endpoint(key) {
        return root.dataset[key].replace('__challenge__', encodeURIComponent(challenge.id));
    }

    function updateResend() {
        const seconds = Math.max(0, Math.ceil((resendAt - Date.now()) / 1000));
        resend.disabled = busy || seconds > 0;
        resend.textContent = seconds ? copy.resend_in.replace(':seconds', seconds) : copy.resend;
        if (challenge?.state === 'waiting' && seconds === 0) {
            waiting.hidden = true;
            status.textContent = copy.prompt_expired;
        }
    }

    function stop() {
        if (challenge?.state === 'waiting' || challenge?.state === 'approved') {
            void gameRequest(endpoint('cancelUrl'), new FormData()).catch(() => {});
        }
        flow?.abort();
        flow = null;
        challenge = null;
        busy = false;
        revision++;
        clearTimeout(pollTimer);
        clearInterval(countdown);
        start.querySelector('button[type="submit"]').disabled = false;
        showStep(false);
    }

    function cancelStep() {
        stop();
        start.elements.namedItem('username').focus();
    }

    function requestEmail() {
        setTitle(copy.registration_title);
        status.textContent = copy.email_required;
        finish.hidden = false;
        email.focus();
    }

    async function complete() {
        const request = flow;
        busy = true;
        finishButton.disabled = true;
        showError(stepError);
        try {
            const result = await gameRequest(endpoint('finishUrl'), new FormData(finish), request.signal, true);
            if (flow !== request) {
                return;
            }
            if (!result.redirect_url) {
                throw new GameLoginError();
            }
            const destination = new URL(result.redirect_url, window.location.origin);
            if (destination.origin !== window.location.origin) {
                throw new GameLoginError();
            }
            challenge = null;
            if (!continueAuthDialog(finish, destination.href)) {
                window.location.assign(destination.href);
            }
        } catch (error) {
            if (flow === request && error.name !== 'AbortError') {
                if (error.fields?.email) {
                    requestEmail();
                }
                showError(stepError, errorMessage(error));
            }
        } finally {
            if (flow === request) {
                busy = false;
                finishButton.disabled = false;
            }
        }
    }

    function render(result) {
        challenge = result;
        resendAt = Date.now() + result.resend_in * 1000;
        expiresAt = Date.now() + result.expires_in * 1000;
        const isWaiting = result.state === 'waiting';
        waiting.hidden = !isWaiting;
        actions.hidden = !isWaiting;
        if (number.textContent !== String(result.number ?? '')) {
            number.textContent = result.number ?? '';
        }
        if (!isWaiting) {
            clearInterval(countdown);
        }
        if (result.needs_email) {
            requestEmail();
            return;
        }
        setTitle(result.creating_account ? copy.registration_title : copy.confirmation_title);
        const message = copy[result.state] ?? copy.error;
        if (status.textContent !== message) {
            status.textContent = message;
        }
        if (isWaiting) {
            updateResend();
            pollTimer = setTimeout(poll, 2500);
        } else if (result.state === 'approved') {
            void complete();
        } else {
            finish.hidden = true;
        }
    }

    async function poll() {
        const requestRevision = revision;
        try {
            const result = await gameRequest(endpoint('statusUrl'), null, flow.signal);
            if (flow && requestRevision === revision) {
                render(result);
            }
        } catch (error) {
            if (flow && requestRevision === revision && error.name !== 'AbortError') {
                status.textContent = copy.error;
                pollTimer = setTimeout(poll, 5000);
            }
        }
    }

    root.hidden = false;
    if (password && toggle) {
        password.hidden = true;
        toggle.hidden = false;
        toggle.addEventListener('click', () => {
            if (flow) {
                stop();
            }
            const showPassword = password.hidden;
            root.hidden = showPassword;
            password.hidden = !showPassword;
            toggle.textContent = showPassword ? toggle.dataset.gameLabel : toggle.dataset.standardLabel;
            const username = start.elements.namedItem('username');
            const identifier = password.querySelector('[name="login"], [name="username"]');
            if (showPassword && !identifier.value) {
                identifier.value = username.value;
            }
            (showPassword ? identifier : username).focus();
        });
    }
    start.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (flow) {
            return;
        }
        const request = new AbortController();
        flow = request;
        const button = start.querySelector('button[type="submit"]');
        button.disabled = true;
        finishButton.disabled = false;
        showError(startError);
        try {
            const result = await gameRequest(root.dataset.startUrl, new FormData(start), request.signal);
            if (flow !== request) {
                return;
            }
            finish.reset();
            finish.hidden = true;
            showError(stepError);
            showStep(true);
            countdown = setInterval(() => {
                if (challenge?.state === 'waiting' && Date.now() >= expiresAt) {
                    revision++;
                    clearTimeout(pollTimer);
                    render({ ...challenge, state: 'expired' });
                    return;
                }
                updateResend();
            }, 1000);
            render(result);
            if (result.state !== 'approved' || !result.needs_email) {
                stepTitle?.focus();
            }
        } catch (error) {
            if (flow !== request) {
                return;
            }
            stop();
            if (error.name !== 'AbortError') {
                showError(startError, errorMessage(error));
            }
        } finally {
            window.KraftCaptcha?.reset?.(start);
        }
    });
    resend.addEventListener('click', async () => {
        if (!flow || busy || Date.now() < resendAt) {
            return;
        }
        const request = flow;
        revision++;
        clearTimeout(pollTimer);
        busy = true;
        updateResend();
        showError(stepError);
        try {
            const result = await gameRequest(endpoint('resendUrl'), new FormData(), request.signal);
            if (flow === request) {
                render(result);
            }
        } catch (error) {
            if (flow === request && error.name !== 'AbortError') {
                showError(stepError, errorMessage(error));
                pollTimer = setTimeout(poll, 2500);
            }
        } finally {
            if (flow === request) {
                busy = false;
                updateResend();
            }
        }
    });
    finish.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!busy && flow && challenge?.state === 'approved') {
            void complete();
        }
    });
    step.querySelector('[data-game-login-back]').addEventListener('click', cancelStep);
    (shell ?? step).addEventListener('keydown', (event) => {
        if (!step.hidden && event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            cancelStep();
        }
    }, true);
    shell?.addEventListener('hide.bs.modal', stop);
}

function initialize(root = document) {
    if (root.matches?.('[data-game-login]')) {
        initializeGameLogin(root);
    }
    root.querySelectorAll('[data-game-login]').forEach(initializeGameLogin);
}

initialize();
document.addEventListener('kraft:content-added', (event) => initialize(event.target));
