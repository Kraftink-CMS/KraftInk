import Tab from 'bootstrap/js/dist/tab';
import Dropdown from 'bootstrap/js/dist/dropdown';
import Collapse from 'bootstrap/js/dist/collapse';
import { normalizeSlug } from './app.js';
import { initRoleAppearance } from './role-appearance.js';
import './theme-configuration.js';
import './select-options.js';
import './admin/media-picker.js';
import './admin/content-cover.js';
import './admin/seo.js';
import './admin/content-seo.js';
import './admin/navigation-pagination.js';
import './admin/market-settings.js';
import '../vendor/admin-lte/js/adminlte.js';

const initializedControls = new WeakMap();

function confirmedFetch(form, input, options) {
    return window.KraftConfirmation ? window.KraftConfirmation.fetch(form, input, options) : fetch(input, options);
}

function confirmationCancelled(error) {
    return window.KraftConfirmation?.isCancelled(error) ?? false;
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

function initializeUpdateScreen() {
    const terminalStates = new Set(['completed', 'rolled_back', 'recovery_required', 'failed']);
    const updateScreen = document.querySelector('[data-update-screen]');

    if (updateScreen) {
        const labels = JSON.parse(updateScreen.dataset.labels);
        const controllers = new Map();
        let history = updateScreen.querySelector('[data-update-history]');
        const template = updateScreen.querySelector('[data-update-template]');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        let preparing = false;
        let activeRun = null;
        let historyLoading = false;

        const sameOrigin = (value) => {
            if (typeof value !== 'string' || !value) {
                return null;
            }
            try {
                const url = new URL(value, window.location.href);
                return url.origin === window.location.origin && !url.username && !url.password ? url.href : null;
            } catch {
                return null;
            }
        };

        const lockActions = (locked) => {
            updateScreen
                .querySelectorAll('[data-update-prepare], [data-update-start], [data-update-check]')
                .forEach((form) => {
                    form.querySelectorAll('button, input').forEach((field) => {
                        field.disabled = locked;
                    });
                });
        };

        const errorMessage = (data, fallback) => {
            const errors = Object.values(data?.errors ?? {})
                .flat()
                .filter((item) => typeof item === 'string');
            return (errors[0] ?? (typeof data?.message === 'string' ? data.message : fallback)).slice(0, 600);
        };

        const showFormMessage = (form, text, isError = false) => {
            const message = form.querySelector('[data-update-error]');
            if (!message) {
                return;
            }
            message.hidden = false;
            message.classList.add('alert');
            message.classList.toggle('alert-danger', isError);
            message.classList.toggle('alert-info', !isError);
            message.setAttribute('role', isError ? 'alert' : 'status');
            message.textContent = text;
        };

        const readJson = async (response) => {
            try {
                const envelope = await response.json();
                if (typeof envelope.success !== 'boolean' || (response.ok && !envelope.success)) {
                    return null;
                }
                return envelope.data;
            } catch {
                return null;
            }
        };

        const attachPanel = (panel) => {
            const id = panel.dataset.updateRun;
            if (!/^[0-9]{14}-[a-f0-9]{12}$/.test(id)) {
                return null;
            }
            if (controllers.has(id)) {
                return controllers.get(id);
            }
            const runLabels = labels.operations?.[panel.dataset.updateOperation] ?? labels;
            const form = panel.querySelector('[data-update-start]');
            const progress = panel.querySelector('[data-update-progress]');
            const status = panel.querySelector('[data-update-status]');
            const percent = panel.querySelector('[data-update-percent]');
            const message = panel.querySelector('[data-update-message]');
            const recovery = panel.querySelector('[data-update-recovery]');
            const retry = panel.querySelector('[data-update-retry]');
            let timer;
            let generation = 0;
            let monitoring = false;
            let launchRequested = false;
            let finished = terminalStates.has(panel.dataset.updateState);
            let reloadScheduled = false;

            const stopMonitoring = () => {
                monitoring = false;
                generation++;
                clearTimeout(timer);
            };

            const render = (data) => {
                if (!data || data.id !== id || typeof data.state !== 'string') {
                    return false;
                }
                if (finished && !terminalStates.has(data.state)) {
                    return true;
                }
                const value = Number(data.progress);
                const amount = Number.isFinite(value) ? Math.max(0, Math.min(100, Math.round(value))) : 0;
                panel.dataset.updateState = data.state;
                progress.value = amount;
                percent.textContent = String(amount) + '%';
                status.textContent = runLabels.states[data.state] ?? runLabels.unknown;
                message.textContent =
                    data.state === 'failed'
                        ? runLabels.failure
                        : terminalStates.has(data.state)
                          ? runLabels.finished
                          : data.state === 'staged'
                            ? launchRequested
                                ? runLabels.starting
                                : runLabels.ready
                            : runLabels.running;
                recovery.hidden = data.state !== 'recovery_required';
                retry.hidden = true;
                if (terminalStates.has(data.state)) {
                    finished = true;
                    stopMonitoring();
                    if (form) {
                        form.hidden = true;
                    }
                    if (activeRun === id) {
                        activeRun = null;
                    }
                    if (data.state !== 'recovery_required') {
                        lockActions(false);
                    }
                    if (['completed', 'rolled_back'].includes(data.state) && !reloadScheduled) {
                        reloadScheduled = true;
                        setTimeout(() => {
                            const page = updateScreen.querySelector('[data-ajax-pagination]');
                            if (page?.dataset['pageName']) {
                                const url = new URL(window.location.href);
                                url.searchParams.delete(page.dataset['pageName']);
                                url.searchParams.delete('history_page');
                                url.hash = 'update-history';
                                window.location.assign(url.href);
                            } else {
                                window.location.reload();
                            }
                        }, 1600);
                    }
                }
                return true;
            };

            const poll = async (cycle) => {
                if (!monitoring || finished || cycle !== generation) {
                    return;
                }
                let delay = 1600;
                const timeout = new AbortController();
                const timeoutId = setTimeout(() => timeout.abort(), 10000);
                try {
                    const progressUrl = sameOrigin(panel.dataset.progressUrl);
                    if (!progressUrl) {
                        throw new Error('invalid_progress_url');
                    }
                    const response = await fetch(progressUrl, {
                        cache: 'no-store',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Kraft-Update-Token': panel.dataset.updateToken },
                        signal: timeout.signal,
                    });
                    const data = await readJson(response);
                    if (cycle !== generation) {
                        return;
                    }
                    if (!response.ok || !render(data)) {
                        throw new Error('progress_unavailable');
                    }
                } catch {
                    if (cycle !== generation) {
                        return;
                    }
                    message.textContent = runLabels['connection_lost'];
                    retry.hidden = false;
                    delay = 4000;
                } finally {
                    clearTimeout(timeoutId);
                }
                if (monitoring && !finished && cycle === generation) {
                    timer = setTimeout(() => poll(cycle), delay);
                }
            };

            const monitor = (force = false) => {
                if (finished || (monitoring && !force)) {
                    return;
                }
                if (!panel.dataset.updateToken) {
                    message.textContent = runLabels['tracking_unavailable'];
                    return;
                }
                monitoring = true;
                const cycle = ++generation;
                clearTimeout(timer);
                void poll(cycle);
            };

            const launch = async (body, runUrl) => {
                if (finished || (activeRun && activeRun !== id) || launchRequested) {
                    return;
                }
                const target = sameOrigin(runUrl);
                if (!target) {
                    message.textContent = runLabels.failure;
                    return;
                }
                activeRun = id;
                launchRequested = true;
                lockActions(true);
                if (form) {
                    form.setAttribute('aria-busy', 'true');
                }
                message.textContent = runLabels.starting;
                monitor();
                body.delete('archive');
                body.delete('version');
                const request = new Request(target, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                    body,
                });
                body.delete('password');
                body.delete('code');
                try {
                    const response = await confirmedFetch(form ?? updateScreen, request);
                    const data = await readJson(response);
                    if (!response.ok && data?.state === 'staged' && render(data)) {
                        stopMonitoring();
                        launchRequested = false;
                        activeRun = null;
                        lockActions(false);
                        message.textContent = runLabels.not_started;
                        if (form) {
                            form.hidden = false;
                            form.setAttribute('aria-busy', 'false');
                        }
                        return;
                    }
                    if (data?.state && render(data)) {
                        return;
                    }
                    if (response.status >= 400 && response.status < 500 && response.status !== 409) {
                        stopMonitoring();
                        launchRequested = false;
                        activeRun = null;
                        lockActions(false);
                        message.textContent = errorMessage(data, runLabels.failure);
                        if (form) {
                            form.hidden = false;
                            form.setAttribute('aria-busy', 'false');
                        }
                        return;
                    }
                    throw new Error('launch_unconfirmed');
                } catch (error) {
                    if (confirmationCancelled(error)) {
                        stopMonitoring();
                        launchRequested = false;
                        activeRun = null;
                        lockActions(false);
                        message.textContent = runLabels.not_started;
                        form?.setAttribute('aria-busy', 'false');
                        return;
                    }
                    message.textContent = runLabels['connection_lost'];
                    retry.hidden = false;
                    monitor();
                }
            };

            const submitRun = (event) => {
                event.preventDefault();
                if (preparing || activeRun || historyLoading || finished || launchRequested) {
                    if (!finished) {
                        message.textContent = runLabels.another_running;
                    }
                    return;
                }
                if (!form.reportValidity()) {
                    return;
                }
                void launch(new FormData(form), form.action);
            };
            const retryTracking = () => monitor(true);
            form?.addEventListener('submit', submitRun);
            retry?.addEventListener('click', retryTracking);
            const resumeOnline = () => {
                if (monitoring && !finished) {
                    monitor(true);
                }
            };
            window.addEventListener('online', resumeOnline);
            const controller = {
                launch,
                dispose: () => {
                    stopMonitoring();
                    window.removeEventListener('online', resumeOnline);
                    form?.removeEventListener('submit', submitRun);
                    retry?.removeEventListener('click', retryTracking);
                },
            };
            controllers.set(id, controller);
            if (panel.hasAttribute('data-update-resume') && !finished) {
                activeRun = id;
                launchRequested = true;
                lockActions(true);
                monitor();
            }
            return controller;
        };

        updateScreen.querySelectorAll('[data-update-run]').forEach(attachPanel);
        if (updateScreen.querySelector('[data-ajax-pagination]')) {
            updateScreen.addEventListener('pagination:before', (event) => {
                if (preparing || activeRun) {
                    event.preventDefault();
                    return;
                }
                historyLoading = true;
                lockActions(true);
            });
            updateScreen.addEventListener('pagination:replace', () => {
                controllers.forEach((controller) => controller.dispose());
                controllers.clear();
            });
            updateScreen.addEventListener('pagination:after', () => {
                historyLoading = false;
                history = updateScreen.querySelector('[data-update-history]');
                lockActions(false);
                updateScreen.querySelectorAll('[data-update-run]').forEach(attachPanel);
            });
            updateScreen.addEventListener('pagination:error', () => {
                historyLoading = false;
                lockActions(false);
            });
        }
        updateScreen.querySelectorAll('[data-update-prepare]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (preparing || activeRun || historyLoading || !form.reportValidity()) {
                    return;
                }
                preparing = true;
                const body = new FormData(form);
                lockActions(true);
                form.setAttribute('aria-busy', 'true');
                showFormMessage(form, labels.preparing);
                try {
                    const response = await confirmedFetch(form, form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                        body,
                    });
                    const data = await readJson(response);
                    if (!response.ok) {
                        showFormMessage(form, errorMessage(data, labels.failure), true);
                        return;
                    }
                    const runUrl = sameOrigin(data?.run_url);
                    const progressUrl = sameOrigin(data?.progress_url);
                    if (
                        !data ||
                        !/^[0-9]{14}-[a-f0-9]{12}$/.test(data.id) ||
                        !runUrl ||
                        !progressUrl ||
                        typeof data.token !== 'string' ||
                        !data.token ||
                        data.token.length > 256 ||
                        data.state !== 'staged'
                    ) {
                        throw new Error('invalid_preparation');
                    }
                    const panel = template.content.firstElementChild.cloneNode(true);
                    panel.dataset.updateRun = data.id;
                    panel.dataset.updateState = data.state;
                    panel.dataset.progressUrl = progressUrl;
                    panel.dataset.updateToken = data.token;
                    panel.querySelector('[data-update-version]').textContent =
                        typeof data.subject === 'string'
                            ? data.subject.slice(0, 180)
                            : typeof data.version === 'string'
                              ? data.version.slice(0, 80)
                              : data.id;
                    panel.querySelector('[data-update-id]').textContent = data.id;
                    panel.querySelector('[data-update-recovery-command]').textContent =
                        'php artisan kraft:update --recover=' + data.id;
                    const runForm = panel.querySelector('[data-update-start]');
                    runForm.action = runUrl;
                    history.querySelector('[data-update-empty]')?.remove();
                    history.closest('[data-update-history-shell]').hidden = false;
                    history.prepend(panel);
                    panel.dispatchEvent(new CustomEvent('kraft:content-added', { bubbles: true }));
                    const pageSize = Number(
                        updateScreen.querySelector('[data-update-history-page]')?.dataset['pageSize'],
                    );
                    if (pageSize > 0) {
                        [...history.querySelectorAll('[data-update-run]')].slice(pageSize).forEach((previous) => {
                            controllers.get(previous.dataset.updateRun)?.dispose();
                            controllers.delete(previous.dataset.updateRun);
                            previous.remove();
                        });
                    }
                    const controller = attachPanel(panel);
                    form.querySelector('[data-update-error]').hidden = true;
                    panel.focus({ preventScroll: false });
                    const file = form.querySelector('input[type="file"]');
                    if (file) {
                        file.value = '';
                    }
                    await controller.launch(body, runUrl);
                } catch (error) {
                    if (confirmationCancelled(error)) {
                        form.querySelector('[data-update-error]').hidden = true;
                    } else {
                        showFormMessage(form, labels.prepare_unknown, true);
                    }
                } finally {
                    preparing = false;
                    body.delete('password');
                    body.delete('code');
                    form.setAttribute('aria-busy', 'false');
                    if (!activeRun) {
                        lockActions(false);
                    }
                }
            });
        });
    }
}

initializeUpdateScreen();

function initializeUpdateAvailability() {
    const banner = document.querySelector('[data-update-availability]');

    if (banner) {
        const endpoint = new URL(banner.dataset.endpoint, window.location.href);
        const cacheKey = 'kraftink.update-availability.' + banner.dataset.cacheKey;
        const categories = ['cms', 'kraftlink', 'plugins', 'themes', 'games'];
        const cmsNotice = banner.querySelector('[data-update-availability-notice="cms"]');
        const cmsBlocked = banner.querySelector('[data-update-availability-cms-blocked]');
        const kraftLinkNotice = banner.querySelector('[data-update-availability-notice="kraftlink"]');
        const kraftLinkReleaseLink = banner.querySelector('[data-update-availability-kraftlink-link]');
        const dismissKraftLink = banner.querySelector('[data-update-availability-kraftlink-dismiss]');
        const dismissError = banner.querySelector('[data-update-availability-kraftlink-error]');
        const extensionsNotice = banner.querySelector('[data-update-availability-notice="extensions"]');
        const skipped = new Set((banner.dataset.skip ?? '').split(','));
        const allowed = {
            cms: banner.dataset.canCms === '1',
            kraftlink: banner.dataset.canKraftlink === '1',
            plugins: banner.dataset.canExtensions === '1',
            themes: banner.dataset.canExtensions === '1',
            games: banner.dataset.canExtensions === '1',
        };
        let currentSummary = null;
        let dismissedKraftLinkVersion = null;
        const validVersion = (version) => typeof version === 'string' && version.length > 0 && version.length <= 80;
        const validSummary = (value) =>
            value &&
            (value.cms === null || validVersion(value.cms)) &&
            (value.cmsBlockedVersion === undefined || (value.cms !== null && validVersion(value.cmsBlockedVersion))) &&
            (value.kraftlink === undefined || validVersion(value.kraftlink)) &&
            ['plugins', 'themes', 'games'].every(
                (category) => Number.isSafeInteger(value[category]) && value[category] >= 0 && value[category] <= 10000,
            );
        const render = (summary) => {
            currentSummary = summary;
            const visible = { cms: false, kraftlink: false, extensions: false };
            categories.forEach((category) => {
                const item = banner.querySelector('[data-update-availability-' + category + ']');
                if (!item) {
                    return;
                }
                const value = summary?.[category];
                const available =
                    allowed[category] &&
                    !skipped.has(category) &&
                    Boolean(value) &&
                    (category !== 'kraftlink' || value !== dismissedKraftLinkVersion);
                item.hidden = !available;
                if (available) {
                    const notice = category in visible ? category : 'extensions';
                    const label = item.dataset.label.replace(notice === 'extensions' ? ':count' : ':version', value);
                    if (item.textContent !== label) {
                        item.textContent = label;
                    }
                    visible[notice] = true;
                }
            });
            if (cmsNotice) {
                cmsNotice.hidden = !visible.cms;
            }
            if (cmsBlocked) {
                const version = visible.cms ? summary?.cmsBlockedVersion : null;
                cmsBlocked.hidden = !version;
                if (version) {
                    const label = cmsBlocked.dataset.label.replace(':version', version);
                    if (cmsBlocked.textContent !== label) {
                        cmsBlocked.textContent = label;
                    }
                }
            }
            if (extensionsNotice) {
                extensionsNotice.hidden = !visible.extensions;
            }
            if (kraftLinkNotice) {
                kraftLinkNotice.hidden = !visible.kraftlink;
                if (visible.kraftlink && kraftLinkReleaseLink) {
                    kraftLinkReleaseLink.href =
                        kraftLinkReleaseLink.dataset.releaseBase + encodeURIComponent(summary.kraftlink);
                }
            }
            banner.hidden = !Object.values(visible).some(Boolean);
        };
        const readCache = () => {
            try {
                const raw = window.sessionStorage.getItem(cacheKey);
                if (!raw || raw.length > 2048) {
                    return null;
                }
                const entry = JSON.parse(raw);
                if (
                    !Number.isSafeInteger(entry.expires_at) ||
                    entry.expires_at > Date.now() + 600000 ||
                    (entry.summary !== null && !validSummary(entry.summary))
                ) {
                    return null;
                }
                return entry;
            } catch {
                return null;
            }
        };
        const saveCache = (summary, seconds) => {
            try {
                window.sessionStorage.setItem(
                    cacheKey,
                    JSON.stringify({ summary, expires_at: Date.now() + seconds * 1000 }),
                );
            } catch {}
        };
        let refreshTimer;
        let checking = false;
        const scheduleRefresh = (milliseconds) => {
            window.clearTimeout(refreshTimer);
            refreshTimer = window.setTimeout(refresh, Math.max(1000, milliseconds));
        };
        const refresh = async () => {
            if (endpoint.origin !== window.location.origin || !Object.values(allowed).some(Boolean)) {
                return;
            }
            if (document.hidden || checking) {
                return;
            }
            const cached = readCache();
            if (cached && cached.expires_at > Date.now()) {
                render(cached.summary);
                scheduleRefresh(cached.expires_at - Date.now());
                return;
            }
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            if (!token) {
                return;
            }
            const controller = new AbortController();
            checking = true;
            const timeout = window.setTimeout(() => controller.abort(), 20000);
            let nextCheck = 60;
            try {
                const response = await fetch(endpoint.href, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                });
                if (!response.ok) {
                    throw new Error('Update availability is temporarily unavailable.');
                }
                const envelope = await response.json();
                if (envelope.success !== true || !envelope.data) {
                    throw new Error('Invalid update availability response.');
                }
                const data = envelope.data;
                if (!Array.isArray(data.plugins) || !Array.isArray(data.themes) || !Array.isArray(data.games)) {
                    throw new Error('Invalid update availability response.');
                }
                const summary = {
                    cms: allowed.cms ? (data.cms === null ? null : data.cms?.version) : null,
                    ...(allowed.cms && validVersion(data.cms?.blocked_version)
                        ? { cmsBlockedVersion: data.cms.blocked_version }
                        : {}),
                    ...(allowed.kraftlink && validVersion(data.kraftlink?.version)
                        ? { kraftlink: data.kraftlink.version }
                        : {}),
                    plugins: allowed.plugins ? data.plugins.length : 0,
                    themes: allowed.themes ? data.themes.length : 0,
                    games: allowed.games ? data.games.length : 0,
                };
                if (!validSummary(summary)) {
                    throw new Error('Invalid update availability summary.');
                }
                const seconds = Number.isFinite(data.refresh_after)
                    ? Math.min(600, Math.max(60, data.refresh_after))
                    : 600;
                nextCheck = seconds;
                saveCache(summary, seconds);
                render(summary);
            } catch {
                saveCache(null, 60);
                render(null);
            } finally {
                checking = false;
                window.clearTimeout(timeout);
                scheduleRefresh(nextCheck * 1000);
            }
        };
        dismissKraftLink?.addEventListener('click', async () => {
            const version = currentSummary?.kraftlink;
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const dismissEndpoint = new URL(dismissKraftLink.dataset.endpoint, window.location.href);
            if (!allowed.kraftlink || !version || !token || dismissEndpoint.origin !== window.location.origin) {
                return;
            }
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 8000);
            dismissKraftLink.disabled = true;
            dismissError.hidden = true;
            try {
                const response = await fetch(dismissEndpoint.href, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ version }),
                    signal: controller.signal,
                });
                if (response.status === 409 || response.status === 403) {
                    try {
                        window.sessionStorage.removeItem(cacheKey);
                    } catch {}
                    await refresh();
                    return;
                }
                if (!response.ok || (await response.json()).success !== true) {
                    throw new Error('KraftLink notification could not be dismissed.');
                }
                dismissedKraftLinkVersion = version;
                if (currentSummary?.kraftlink === version) {
                    const { kraftlink, ...summary } = currentSummary;
                    saveCache(summary, 60);
                    render(summary);
                }
            } catch {
                dismissError.textContent = dismissKraftLink.dataset.error;
                dismissError.hidden = false;
            } finally {
                window.clearTimeout(timeout);
                dismissKraftLink.disabled = false;
            }
        });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                window.clearTimeout(refreshTimer);
            } else {
                void refresh();
            }
        });
        const schedule = () => {
            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(refresh, { timeout: 2000 });
            } else {
                window.setTimeout(refresh, 500);
            }
        };
        if (document.readyState === 'complete') {
            schedule();
        } else {
            window.addEventListener('load', schedule, { once: true });
        }
    }
}

initializeUpdateAvailability();

function initializeServerFields(root = document) {
    root.querySelectorAll('[data-server-fields]').forEach((fields) => {
        if (!initializeControl(fields, 'server')) {
            return;
        }
        const game = fields.querySelector('[data-server-game]');
        const connections = fields.querySelectorAll('[data-server-connection]');
        const port = fields.querySelector('[data-server-port]');
        const host = fields.querySelector('[name="host"]');
        const playerDisplay = fields.querySelector('[data-server-player-display]');
        const activation = fields.querySelector('[data-server-activation]');
        const submit = fields.closest('form').querySelector('.modal-footer button[type="submit"]');
        const original = fields.dataset.serverOriginal ? JSON.parse(fields.dataset.serverOriginal) : null;
        const updateTransport = () => {
            const selected = fields.querySelector('[data-server-connection]:checked');
            const connection = selected?.value ?? '';
            const hasPlayerStatus = selected?.dataset.playerStatus === '1';
            playerDisplay.hidden = !hasPlayerStatus;
            playerDisplay.disabled = !hasPlayerStatus;
            const statusOnly = selected?.dataset.statusOnly === '1';
            activation.hidden = statusOnly;
            activation.disabled = statusOnly;
            let secret = false;
            let changedOptions = false;
            let suppliedSecret = false;
            fields.querySelectorAll('[data-server-transport-mode]').forEach((fieldset) => {
                const active =
                    fieldset.dataset.serverTransportGame === game.value &&
                    fieldset.dataset.serverTransportMode === connection;
                fieldset.hidden = !active;
                fieldset.disabled = !active;
                if (active && fieldset.dataset.serverNeedsSecret === '1') {
                    secret = true;
                    changedOptions = Array.from(fieldset.querySelectorAll('[data-server-transport-option]')).some(
                        (input) => input.value !== input.dataset.serverOriginalValue,
                    );
                    suppliedSecret = Boolean(fieldset.querySelector('[name="transport_secret"]')?.value);
                }
            });
            fields.querySelectorAll('[data-server-connection-row]').forEach((row) => {
                row.classList.toggle('table-active', row.querySelector('[data-server-connection]').checked);
            });
            if (selected) {
                submit.textContent = selected.dataset.saveLabel;
                const enabledLabel = fields.querySelector('[data-server-enabled-label]');
                const enabledHelp = fields.querySelector('[data-server-enabled-help]');
                enabledLabel.textContent = selected.dataset.enabledLabel;
                enabledHelp.textContent = selected.dataset.enabledHelp;
                enabledHelp.hidden = !selected.dataset.enabledHelp;
            }
            const confirmation = fields.querySelector('[data-server-confirmation]');
            if (confirmation) {
                const hasStoredSecret = confirmation.dataset.serverHasSecret === '1';
                const changedEndpoint =
                    !original ||
                    game.value !== original.game ||
                    connection !== original.connection ||
                    host.value !== original.host ||
                    port.value !== String(original.port);
                const required =
                    (hasStoredSecret && changedEndpoint) ||
                    (secret && (!hasStoredSecret || suppliedSecret || changedEndpoint || changedOptions));
                confirmation.hidden = !required;
                confirmation.disabled = !required;
            }
        };
        connections.forEach((connection) => connection.addEventListener('change', updateTransport));
        fields
            .querySelectorAll('input:not([data-server-connection])')
            .forEach((input) => input.addEventListener('input', updateTransport));
        fields.closest('.modal')?.addEventListener('show.bs.modal', updateTransport);
        fields.closest('form')?.addEventListener('reset', () => queueMicrotask(updateTransport));
        updateTransport();
    });
}

initializeServerFields();

export function initAvatarSettings(root = document) {
    root.querySelectorAll('[data-avatar-settings]').forEach((form) => {
        if (!initializeControl(form, 'avatar-settings')) {
            return;
        }
        const uploads = form.querySelector('[data-avatar-uploads]');
        const provider = form.querySelector('[data-avatar-provider]');
        const update = () => {
            form.querySelector('[data-avatar-priority]').hidden =
                !uploads.checked || provider.selectedOptions[0]?.dataset.avatarAvailable !== '1';
            form.querySelectorAll('[data-avatar-provider-details]').forEach((details) => {
                details.hidden = details.dataset.avatarProviderDetails !== provider.value;
            });
        };
        uploads.addEventListener('change', update);
        provider.addEventListener('change', update);
        update();
    });
}

initAvatarSettings();

function initializeAuditWebhooks() {
    const secret = document.querySelector('[data-webhook-secret]');
    const url = document.querySelector('[data-webhook-url]');
    const confirmation = secret?.closest('form')?.querySelector('[data-webhook-confirmation]');
    const update = () => {
        if (confirmation) {
            const required = Boolean(secret?.value || url?.value);
            confirmation.hidden = !required;
            confirmation.disabled = !required;
        }
    };
    secret?.addEventListener('input', update);
    url?.addEventListener('input', update);
    document.querySelector('[data-webhook-generate]')?.addEventListener('click', () => {
        if (!secret) {
            return;
        }
        const bytes = crypto.getRandomValues(new Uint8Array(32));
        secret.value = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        secret.dispatchEvent(new Event('input', { bubbles: true }));
        secret.focus();
    });
    document.querySelector('[data-webhook-copy]')?.addEventListener('click', async (event) => {
        if (!secret?.value) {
            return;
        }
        const button = event.currentTarget;
        try {
            await navigator.clipboard.writeText(secret.value);
            const label = button.textContent;
            button.textContent = button.dataset['copied'];
            setTimeout(() => {
                button.textContent = label;
            }, 2000);
        } catch {
            secret.type = 'text';
            secret.focus();
            secret.select();
        }
    });
    update();
}

initializeAuditWebhooks();

function initializeRedirectPreviews(root = document) {
    root.querySelectorAll('[data-redirect-source]').forEach((container) => {
        if (!initializeControl(container, 'redirect')) {
            return;
        }
        const input = container.querySelector('[data-redirect-input]');
        const preview = container.querySelector('[data-redirect-preview]');
        const output = container.querySelector('[data-redirect-path]');
        const render = () => {
            const value = input.value.trim();
            let path = null;
            try {
                if (value && !value.startsWith('//') && !/[\\\u0000-\u001f\u007f]/.test(value)) {
                    if (/^[a-z][a-z0-9+.-]*:/i.test(value)) {
                        const url = new URL(value);
                        if (['http:', 'https:'].includes(url.protocol)) {
                            path = value.match(/^https?:\/\/[^/?#]+([^?#]*)/i)?.[1] || '/';
                        }
                    } else {
                        path = ('/' + value.replace(/^\/+/, '')).split(/[?#]/, 1)[0];
                    }
                    for (let attempt = 0; path && attempt < 3; attempt++) {
                        const decoded = decodeURIComponent(path);
                        if (decoded === path) {
                            break;
                        }
                        path = decoded;
                    }
                    if (
                        path === '/' ||
                        path.length > 500 ||
                        /[?#%\s\\\u0000-\u001f\u007f]/.test(path) ||
                        path.includes('//') ||
                        path.split('/').some((part) => ['.', '..'].includes(part))
                    ) {
                        path = null;
                    }
                    if (
                        /^\/(?:admin|api|account|install|login|logout|register|oauth|reset-password|forgot-password|two-factor-challenge|media|extensions|build|vendor|up|language|notifications|verify-email|verify-game|email)(?:\/|$)/i.test(
                            path ?? '',
                        )
                    ) {
                        path = null;
                    }
                }
            } catch {
                path = null;
            }
            output.textContent = path ?? '';
            preview.hidden = !path;
        };
        input.addEventListener('input', render);
        render();
    });
}

initializeRedirectPreviews();

function initializeOAuthFields(root = document) {
    root.querySelectorAll('[data-oauth-provider-form]').forEach((form) => {
        if (!initializeControl(form, 'oauth')) {
            return;
        }
        const toggle = form.querySelector('[data-oauth-activate]');
        const client = form.querySelector('[data-oauth-client]');
        const secret = form.querySelector('[data-oauth-secret]');
        const update = () => {
            const enabled = toggle ? toggle.checked : form.querySelector('input[name="enabled"]').value === '1';
            if (client) {
                client.required = enabled && client.dataset['oauthRequired'] !== '0';
            }
            if (secret) {
                secret.required =
                    enabled && secret.dataset['oauthRequired'] !== '0' && secret.dataset['hasSecret'] !== '1';
            }
        };
        toggle?.addEventListener('change', update);
        update();
    });
}

initializeOAuthFields();

function initializeSocialLinks(root = document) {
    root.querySelectorAll('[data-social-form]').forEach((form) => {
        if (!initializeControl(form, 'social')) {
            return;
        }
        const type = form.querySelector('[data-social-type]');
        const title = form.elements.namedItem('title');
        const icon = form.elements.namedItem('icon');
        const color = form.elements.namedItem('color');
        const preview = form.querySelector('[data-social-preview]');
        let previous = type.selectedOptions[0]?.dataset;
        const updatePreview = () => {
            preview.className = `bi bi-${/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(icon.value) ? icon.value : 'link-45deg'}`;
            preview.style.color = color.value;
        };
        type.addEventListener('change', () => {
            const selected = type.selectedOptions[0].dataset;
            if (!title.value || title.value === previous.socialTitle) {
                title.value = selected.socialTitle;
            }
            if (!icon.value || icon.value === previous.socialIcon) {
                icon.value = selected.socialIcon;
            }
            if (!color.value || color.value === previous.socialColor) {
                color.value = selected.socialColor;
            }
            previous = selected;
            updatePreview();
        });
        icon.addEventListener('input', updatePreview);
        color.addEventListener('input', updatePreview);
        updatePreview();
    });
}

initializeSocialLinks();

function initializeMailSettings() {
    document.querySelectorAll('[data-mail-settings]').forEach((form) => {
        const select = form.querySelector('[data-mail-transport]');
        const confirmation = form.querySelector('[data-mail-confirmation]');
        const original = form.dataset?.mailOriginal ? JSON.parse(form.dataset.mailOriginal) : null;
        const credential = form.querySelector('[name="mail_password"]');
        const clear = form.querySelector('[name="clear_password"]');
        const update = () => {
            const smtp = select.value === 'smtp';
            const fields = form.querySelector('[data-mail-smtp]');
            fields.hidden = !smtp;
            fields.disabled = !smtp;
            form.querySelector('[data-mail-sendmail]').hidden = smtp;
            if (confirmation) {
                const hasPassword = form.dataset.mailHasPassword === '1';
                const secretChanged =
                    smtp && ((!clear?.checked && Boolean(credential?.value)) || (clear?.checked && hasPassword));
                const endpointChanged =
                    original &&
                    (select.value !== original.transport ||
                        (smtp &&
                            ['host', 'port', 'security'].some(
                                (key) =>
                                    (form.querySelector(`[name="${key}"]`)?.value ?? '') !==
                                    String(original[key] ?? ''),
                            )));
                const identityChanged =
                    smtp &&
                    hasPassword &&
                    original &&
                    (form.querySelector('[name="username"]')?.value ?? '') !== String(original.username ?? '');
                const required = secretChanged || endpointChanged || identityChanged;
                confirmation.hidden = !required;
                confirmation.disabled = !required;
            }
        };
        select.addEventListener('change', update);
        form.querySelectorAll?.('input, select').forEach((input) => {
            input.addEventListener('input', update);
            input.addEventListener('change', update);
        });
        update();
    });
}

initializeMailSettings();

function initializeSiteIdentity() {
    document.querySelectorAll('[data-site-url]').forEach((group) => {
        const field = group.querySelector('[name="site_url"]');
        const proof = group.querySelector('[data-site-url-proof]');
        if (!field || !proof) {
            return;
        }
        const normalize = (value) => {
            try {
                const base = new URL(field.dataset.currentUrl);
                const url = new URL(value.includes('://') ? value : `${base.protocol}//${value}`);
                return url.origin + url.pathname.replace(/\/+$/, '');
            } catch {
                return value.trim();
            }
        };
        const update = () => {
            const changed = normalize(field.value) !== normalize(field.dataset.currentUrl);
            proof.hidden = !changed;
            proof.disabled = !changed;
            if (field.form?.dataset.ajax === 'true') {
                field.form.dataset.redirectUrl = changed ? 'response' : 'false';
            }
            proof.querySelectorAll('input').forEach((input) => {
                input.disabled = !changed;
                input.required = changed;
            });
        };
        field.addEventListener('input', update);
        update();
    });
}

initializeSiteIdentity();

function initializeWalletForms(root = document) {
    const modal = root.querySelector('#wallet-adjust');
    const form = modal?.querySelector('[data-wallet-form]');

    if (form && initializeControl(form, 'wallet')) {
        const label = form.querySelector('[data-wallet-amount-label]');
        const help = form.querySelector('[data-wallet-amount-help]');
        const amount = form.querySelector('[name="amount"]');
        const preview = form.querySelector('[data-wallet-preview]');
        const finalBalance = form.querySelector('[data-wallet-final-balance]');
        const feedback = form.querySelector('[data-wallet-preview-feedback]');
        const maximum = BigInt(form.dataset['walletMaxBalance']);
        const french = document.documentElement.lang.startsWith('fr');
        const format = (minor) => {
            const whole = (minor / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, french ? '\u00a0' : ',');
            const fraction = (minor % 100n).toString().padStart(2, '0');
            return `${whole}${french ? ',' : '.'}${fraction} ${form.dataset['walletCurrency']}`;
        };
        const update = () => {
            const direction = form.querySelector('[name="direction"]:checked')?.value;
            const defining = direction === 'set';
            label.textContent = defining ? label.dataset['targetLabel'] : label.dataset['amountLabel'];
            help.textContent = defining ? help.dataset['targetHelp'] : help.dataset['amountHelp'];
            const value = amount.value.trim();
            const match = /^(\d{1,14})(?:[.,](\d{1,2}))?$/.exec(value);
            let result = null;
            let error = '';
            if (value !== '') {
                if (!match) {
                    error = preview.dataset['invalidAmount'];
                } else {
                    const minor = BigInt(match[1]) * 100n + BigInt((match[2] || '').padEnd(2, '0'));
                    const balance = BigInt(form.dataset.walletBalanceMinor);
                    if (minor > maximum || (!defining && minor === 0n)) {
                        error = preview.dataset['invalidAmount'];
                    } else {
                        result = defining ? minor : direction === 'debit' ? balance - minor : balance + minor;
                        if (result < 0n) {
                            error = preview.dataset['insufficientBalance'];
                        } else if (result > maximum) {
                            error = preview.dataset['balanceLimit'];
                        }
                    }
                }
            }
            const valid = result !== null && error === '';
            finalBalance.textContent = valid ? format(result) : '—';
            finalBalance.classList.toggle('text-body-secondary', !valid);
            finalBalance.classList.toggle('text-primary', valid);
            feedback.textContent = error;
            feedback.hidden = error === '';
            amount.setCustomValidity(error);
            amount.classList.toggle('is-invalid', error !== '');
            amount.setAttribute('aria-invalid', String(error !== ''));
        };
        amount.addEventListener('input', update);
        form.querySelectorAll('[name="direction"]').forEach((input) => input.addEventListener('change', update));
        modal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            if (!trigger?.hasAttribute('data-wallet-member')) {
                return;
            }
            form.querySelector('[name="user_id"]').value = trigger.dataset['walletMember'];
            form.querySelector('[name="request_id"]').value = form.dataset.requestId;
            form.querySelector('[data-wallet-member-username]').textContent = trigger.dataset['walletUsername'];
            form.querySelector('[data-wallet-member-balance]').textContent = trigger.dataset['walletBalance'];
            form.dataset.walletBalanceMinor = trigger.dataset.walletBalanceMinor;
            form.querySelector('[name="direction"][value="credit"]').checked = true;
            for (const name of ['amount', 'reason', 'password', 'code']) {
                const field = form.querySelector(`[name="${name}"]`);
                if (field) {
                    field.value = '';
                }
            }
            form.querySelector('[data-modal-errors]')?.remove();
            update();
        });
        update();
    }
}

initializeWalletForms();

document.querySelectorAll('[data-announcement-webhook]').forEach((input) => {
    const form = input.closest('form');
    const confirmation = form?.querySelector('[data-announcement-proof]');
    const editor = form?.querySelector('[data-announcement-editor]');
    const fields = form?.querySelector('[data-announcement-fields]');
    if (!confirmation) {
        return;
    }
    let editing = !editor || editor.classList.contains('show');
    const update = () => {
        if (fields) {
            fields.disabled = !editing;
        }
        if (!editing) {
            input.value = '';
            confirmation.querySelectorAll('input').forEach((field) => {
                field.value = '';
            });
        }
        const required = editing && input.value !== '';
        confirmation.hidden = !required;
        confirmation.disabled = !required;
    };
    input.addEventListener('input', update);
    editor?.addEventListener('show.bs.collapse', () => {
        editing = true;
        update();
    });
    editor?.addEventListener('hide.bs.collapse', () => {
        editing = false;
        update();
    });
    update();
});

document.querySelectorAll('[data-content-form]').forEach((form) => {
    const title = form.querySelector('[data-content-title]');
    const kind = form.dataset['contentKind'];
    const slug = form.querySelector('[data-content-slug]');
    const prefix = form.querySelector('[data-content-prefix]');
    const preview = form.querySelector('[data-content-preview]');
    let manualSlug = slug.value !== '';
    const update = () => {
        const path = kind === 'page' ? '/p/' : '/news/';
        slug.dataset.slugPaths = String(kind === 'page');
        slug.pattern = kind === 'page' ? '[a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*)*' : '[a-z0-9]+(-[a-z0-9]+)*';
        const address = `${form.dataset.siteUrl.replace(/\/$/, '')}${path}${slug.value}`;
        prefix.textContent = path;
        preview.textContent = address;
        preview.href = address;
    };
    title.addEventListener('input', () => {
        if (manualSlug) {
            return;
        }
        slug.value = normalizeSlug(title.value, { max: 160 });
        update();
    });
    slug.addEventListener('input', () => {
        manualSlug = true;
        update();
    });
    slug.addEventListener('change', update);
    update();
    const visibility = form.querySelector('[data-content-visibility]');
    const roleFields = form.querySelector('[data-content-roles]');
    if (visibility && roleFields) {
        const updateVisibility = () => {
            roleFields.hidden = visibility.value !== 'restricted';
        };
        visibility.addEventListener('change', updateVisibility);
        updateVisibility();
    }
});

function initializeNavigationForms(root = document) {
    root.querySelectorAll('[data-navigation-form]').forEach((form) => {
        if (!initializeControl(form, 'navigation')) {
            return;
        }
        const location = form.querySelector('[data-navigation-location]');
        const parent = form.querySelector('[data-navigation-parent]');
        const address = form.querySelector('[data-navigation-url]');
        const preview = form.querySelector('[data-navigation-preview]');
        const previewContainer = form.querySelector('[data-navigation-preview-container]');
        const choice = form.querySelector('[data-navigation-choice]');
        const custom = form.querySelector('[data-navigation-custom]');
        const label = form.querySelector('[data-navigation-label]');
        const labelFeedback = form.querySelector('[data-navigation-label-feedback]');
        const translations = [...form.querySelectorAll('[data-navigation-translation]')];
        let missingLabelShown = false;
        const validateLabels = () => {
            const valid = translations.some((field) => field.value.trim());
            label.setCustomValidity(valid ? '' : form.dataset.labelRequired);
            if (valid && missingLabelShown) {
                label.classList.remove('is-invalid');
                label.removeAttribute('aria-invalid');
                labelFeedback?.classList.remove('d-block');
                missingLabelShown = false;
            }
        };
        const updatePreview = () => {
            const value = (
                choice.value === 'url' ? address.value : choice.selectedOptions[0]?.dataset.url || ''
            ).trim();
            let destination = null;
            try {
                if ((/^\/(?!\/)/.test(value) || /^https?:\/\//i.test(value)) && !/[\\\x00-\x20\x7f]/.test(value)) {
                    const decoded = decodeURIComponent(value);
                    const url = new URL(value, form.dataset.siteUrl);
                    if (
                        ['http:', 'https:'].includes(url.protocol) &&
                        !url.username &&
                        !url.password &&
                        !decoded.startsWith('//') &&
                        !/[\\\x00-\x1f\x7f]/.test(decoded)
                    ) {
                        destination = url.href;
                    }
                }
            } catch {}
            previewContainer.hidden = destination === null;
            if (destination !== null) {
                preview.href = destination;
                preview.textContent = preview.href;
            } else {
                preview.removeAttribute('href');
                preview.textContent = '';
            }
        };
        const updateChoice = (autofill = false) => {
            custom.hidden = choice.value !== 'url';
            custom.disabled = custom.hidden;
            address.required = !custom.hidden;
            const name = choice.selectedOptions[0]?.dataset.label;
            if (autofill && name && (!label.value || label.dataset.autoValue === label.value)) {
                label.value = name;
                label.dispatchEvent(new Event('input', { bubbles: true }));
                label.dataset.autoValue = name;
            }
            validateLabels();
            updatePreview();
        };
        form.closest('.modal')?.addEventListener('show.bs.modal', (event) => {
            const placement = event.relatedTarget?.dataset['navigationCreateLocation'];
            if (placement) {
                location.value = placement;
                parent.value = '';
                form.elements.namedItem('position').value = event.relatedTarget.dataset['navigationCreatePosition'];
                location.dispatchEvent(new Event('change'));
            }
        });
        choice.addEventListener('change', () => updateChoice(true));
        address.addEventListener('input', updatePreview);
        label.addEventListener('input', () => {
            label.dataset.autoValue = '';
        });
        translations.forEach((field) => field.addEventListener('input', validateLabels));
        label.addEventListener('invalid', (event) => {
            event.preventDefault();
            missingLabelShown = true;
            label.classList.add('is-invalid');
            label.setAttribute('aria-invalid', 'true');
            if (labelFeedback) {
                label.setAttribute('aria-describedby', labelFeedback.id);
                labelFeedback.classList.add('d-block');
            }
            const panel = label.closest('.collapse');
            const focus = () => label.focus();
            if (panel && !panel.classList.contains('show')) {
                panel.addEventListener('shown.bs.collapse', focus, { once: true });
                Collapse.getOrCreateInstance(panel, { toggle: false }).show();
            } else {
                focus();
            }
        });
        updateChoice();
    });
}

initializeNavigationForms();
document.addEventListener('kraft:content-added', (event) => initializeNavigationForms(event.target));

document.querySelectorAll('[data-game-form]').forEach((form) => {
    const select = form.querySelector('[data-active-game]');
    const update = () => {
        if (form.dataset.ajax === 'true' && form.dataset['originalGame']) {
            form.dataset.redirectUrl = select.value !== form.dataset['originalGame'] ? 'response' : 'false';
        }
        form.querySelectorAll('[data-game-settings]').forEach((fieldset) => {
            const game = fieldset.dataset['gameSettings'];
            const inactive = game !== select.value;
            fieldset.hidden = inactive;
            fieldset.disabled = inactive;
            fieldset.querySelectorAll('[data-game-field-conditions]').forEach((section) => {
                const conditions = JSON.parse(section.dataset['gameFieldConditions']);
                const visible = Object.entries(conditions).every(([key, expected]) => {
                    const input = form.elements.namedItem('game_settings[' + game + '][' + key + ']');
                    const toggle = input instanceof RadioNodeList
                        ? Array.from(input).find((element) => element.hasAttribute('data-game-switch'))
                        : null;
                    const value = toggle ? (toggle.checked ? toggle.value : 'disabled') : input?.value;
                    return Array.isArray(expected) ? expected.includes(value) : value === expected;
                });
                section.hidden = !visible;
                section.disabled = !visible;
            });
        });
    };
    form.addEventListener('change', update);
    update();
});

document.querySelectorAll('[data-api-status]').forEach((badge) => {
    const form = badge.closest('form');
    form.addEventListener('ajax:success', () => {
        const enabled = form.querySelector('[name="enabled"][type="checkbox"]').checked;
        badge.classList.toggle('text-bg-success', enabled);
        badge.classList.toggle('text-bg-secondary', !enabled);
        badge.textContent = enabled ? badge.dataset['enabledLabel'] : badge.dataset['disabledLabel'];
    });
});

document.querySelectorAll('[data-member-security]').forEach((form) => {
    const action = form.querySelector('[name="action"]');
    const update = () => {
        form.querySelectorAll('[data-member-fields]').forEach((section) => {
            section.hidden = section.dataset['memberFields'] !== action.value;
            section.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = section.hidden;
                if (field.hasAttribute('data-required-for-action')) {
                    field.required = !section.hidden;
                }
            });
        });
    };
    action.addEventListener('change', update);
    update();
});

function initializeNoticeForms(root = document) {
    root.querySelectorAll('[data-notice-form]').forEach((form) => {
        if (!initializeControl(form, 'notice')) {
            return;
        }
        const audience = form.querySelector('[data-notice-audience]');
        const update = () => {
            form.querySelectorAll('[data-notice-target]').forEach((fields) => {
                fields.hidden = fields.dataset['noticeTarget'] !== audience.value;
                fields.disabled = fields.hidden;
                fields.querySelectorAll('input, select').forEach((field) => {
                    field.required = !fields.hidden;
                });
            });
        };
        audience.addEventListener('change', update);
        update();
    });
}

initializeNoticeForms();

export function initOptionHelp(root = document) {
    root.querySelectorAll('[data-select-help]').forEach((select) => {
        if (!initializeControl(select, 'option-help')) {
            return;
        }
        const target = (root.ownerDocument ?? root).getElementById(select.dataset.selectHelp);
        if (!target) {
            return;
        }
        const update = () => {
            const help = select.selectedOptions[0]?.dataset.help ?? '';
            target.textContent = help;
            target.hidden = help === '';
        };
        select.addEventListener('change', update);
        update();
    });
}

export function initChoiceFields(root = document) {
    root.querySelectorAll('[data-fields-target]').forEach((control) => {
        if (!initializeControl(control, 'choice-fields')) {
            return;
        }
        const target = (root.ownerDocument ?? root).getElementById(control.dataset.fieldsTarget);
        if (!target) {
            return;
        }
        const update = () => {
            const visible = control.type === 'checkbox' ? control.checked : control.selectedOptions[0]?.dataset.showFields === '1';
            target.hidden = !visible;
            target.disabled = !visible;
        };
        control.addEventListener('change', update);
        update();
    });
}

if (typeof document !== 'undefined') {
    initOptionHelp();
    initChoiceFields();
    initRoleAppearance(document);
}

const normalize = (value) =>
    value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase()
        .trim();

function initializeSearchableSelects(root = document) {
    root.querySelectorAll('select[data-searchable-select]:not([disabled])').forEach((select, index) => {
        if (!initializeControl(select, 'searchable')) {
            return;
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'dropdown';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = select.className + ' text-start';
        button.dataset.bsToggle = 'dropdown';
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-haspopup', 'listbox');
        const originalId = select.id || `searchable-select-${index}`;
        button.id = originalId;
        select.id = originalId + '-native';
        for (const attribute of ['aria-label', 'aria-labelledby', 'aria-describedby']) {
            if (select.hasAttribute(attribute)) {
                button.setAttribute(attribute, select.getAttribute(attribute));
            }
        }
        const menu = document.createElement('div');
        menu.className = 'dropdown-menu w-100 p-2';
        const inModal = Boolean(select.closest('.modal'));
        if (inModal) {
            menu.classList.add('position-static', 'mt-1');
        }
        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'form-control form-control-sm mb-2';
        search.placeholder = document.querySelector('meta[name="kraft-select-search"]')?.content || '';
        search.setAttribute('aria-label', search.placeholder);
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-expanded', 'false');
        search.autocomplete = 'off';
        const list = document.createElement('div');
        list.id = originalId + '-options';
        list.setAttribute('role', 'listbox');
        list.setAttribute(
            'aria-label',
            document.querySelector(`label[for="${CSS.escape(originalId)}"]`)?.textContent.trim() || search.placeholder,
        );
        list.className = 'overflow-auto';
        list.style.maxHeight = '15rem';
        search.setAttribute('aria-controls', list.id);
        button.setAttribute('aria-controls', list.id);
        const empty = document.createElement('div');
        empty.className = 'small text-body-secondary px-2 py-3';
        empty.textContent = document.querySelector('meta[name="kraft-select-empty"]')?.content || '';
        empty.setAttribute('role', 'status');
        empty.hidden = true;
        menu.append(search, list, empty);
        select.before(wrapper);
        wrapper.append(button, menu, select);
        select.className = 'visually-hidden';
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        const dropdown = new Dropdown(button, { autoClose: 'outside', display: inModal ? 'static' : 'dynamic' });
        let active = -1;
        let items = [];
        const activate = (index) => {
            items[active]?.classList.remove('active');
            active = index;
            items[active]?.classList.add('active');
            if (items[active]) {
                search.setAttribute('aria-activedescendant', items[active].id);
                list.scrollTop = items[active].offsetTop - list.offsetTop;
            } else {
                search.removeAttribute('aria-activedescendant');
            }
        };
        const render = () => {
            list.replaceChildren();
            active = -1;
            search.removeAttribute('aria-activedescendant');
            items = [];
            const term = normalize(search.value);
            for (const [optionIndex, option] of [...select.options].entries()) {
                if (
                    option.disabled ||
                    option.hidden ||
                    !normalize(option.textContent + ' ' + option.value).includes(term)
                ) {
                    continue;
                }
                const item = document.createElement('button');
                item.type = 'button';
                item.id = `${originalId}-option-${optionIndex}`;
                item.className = 'dropdown-item rounded text-wrap';
                item.classList.toggle('fw-semibold', option.selected);
                item.textContent = option.textContent;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', String(option.selected));
                item.tabIndex = -1;
                item.addEventListener('click', () => {
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    dropdown.hide();
                    button.focus();
                });
                list.append(item);
                items.push(item);
            }
            empty.hidden = items.length > 0;
            const selectedIndex = items.findIndex((item) => item.getAttribute('aria-selected') === 'true');
            if (selectedIndex >= 0) {
                activate(selectedIndex);
            }
        };
        const update = () => {
            button.textContent = select.selectedOptions[0]?.textContent || '';
            render();
        };
        button.addEventListener('shown.bs.dropdown', () => {
            search.value = '';
            render();
            search.setAttribute('aria-expanded', 'true');
            search.focus();
        });
        button.addEventListener('hidden.bs.dropdown', () => search.setAttribute('aria-expanded', 'false'));
        search.addEventListener('input', render);
        search.addEventListener('keydown', (event) => {
            if (['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(event.key)) {
                event.preventDefault();
                event.stopPropagation();
            }
            if (event.key === 'ArrowDown' && items.length) {
                activate((active + 1) % items.length);
            } else if (event.key === 'ArrowUp' && items.length) {
                activate(active < 1 ? items.length - 1 : active - 1);
            } else if (event.key === 'Enter') {
                items[active < 0 ? 0 : active]?.click();
            } else if (event.key === 'Escape') {
                dropdown.hide();
                button.focus();
            } else if (event.key === 'Tab') {
                dropdown.hide();
                button.focus();
            }
        });
        select.addEventListener('change', update);
        select.addEventListener('invalid', (event) => {
            event.preventDefault();
            const pane = select.closest('.tab-pane:not(.active)');
            if (pane) {
                const trigger = document.querySelector(
                    `[data-bs-toggle="tab"][data-bs-target="#${CSS.escape(pane.id)}"]`,
                );
                if (trigger) {
                    Tab.getOrCreateInstance(trigger).show();
                }
            }
            button.focus();
            dropdown.show();
        });
        select.form?.addEventListener('reset', () => setTimeout(update, 0));
        update();
    });
}

initializeSearchableSelects();

document.addEventListener('kraft:content-added', (event) => {
    const root = event.target;
    initializeServerFields(root);
    initializeRedirectPreviews(root);
    initializeOAuthFields(root);
    initializeSocialLinks(root);
    initializeWalletForms(root);
    initAvatarSettings(root);
    initOptionHelp(root);
    initChoiceFields(root);
    initRoleAppearance(root);
    initializeSearchableSelects(root);
    initializeNoticeForms(root);
});
