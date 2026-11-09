@use('Illuminate\Support\Js')
@props(['name', 'value' => 'inherit', 'label', 'provided' => false, 'group' => '', 'denyValue' => 'deny', 'allowValue' => 'allow', 'profileDefaults' => []])
@php
    $toggleId = 'permission_'.hash('xxh128', $name);
    $value = is_scalar($value) ? (string) $value : 'inherit';
    $value = in_array($value, [(string) $denyValue, 'inherit', (string) $allowValue], true) ? $value : 'inherit';
    $inherited = $value === 'inherit';
    $allowed = $value === (string) $allowValue || ($inherited && $provided);
    $inheritLabel = __('admin.members.permission_inherit').' — '.($provided ? __('admin.members.permission_allowed') : __('admin.members.permission_denied'));
    $statusLabel = $inherited
        ? __('admin.members.permission_status_default_'.($allowed ? 'allowed' : 'denied'))
        : __('admin.members.permission_'.($allowed ? 'allowed' : 'denied'));
@endphp
<div class="d-flex align-items-center justify-content-between gap-3 border-bottom pb-3 h-100" role="group"
    aria-label="{{ $label }}" data-permission-toggle="{{ $group }}" data-profile-defaults="{{ json_encode($profileDefaults) }}"
    data-permission-provided="{{ $provided ? '1' : '0' }}" data-permission-allow="{{ $allowValue }}">
    <div class="flex-grow-1 text-break">
        <div>{{ $label }}</div>
        <small class="d-inline-flex align-items-center gap-1 mt-1 {{ $allowed ? 'text-success' : 'text-danger' }}"
            id="{{ $toggleId }}_status" data-permission-status data-permission-state="{{ $allowed ? 'allowed' : 'denied' }}"
            aria-live="polite" aria-atomic="true">
            <i class="bi {{ $allowed ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }}" aria-hidden="true" data-permission-status-icon></i>
            <span data-permission-status-text>{{ $statusLabel }}</span>
        </small>
    </div>
    <div class="btn-group flex-shrink-0">
        <input class="btn-check" type="radio" name="{{ $name }}" id="{{ $toggleId }}_deny" value="{{ $denyValue }}"
            aria-describedby="{{ $toggleId }}_status" @checked($value === (string) $denyValue)>
        <label class="btn btn-outline-danger" for="{{ $toggleId }}_deny" title="{{ __('admin.members.permission_deny') }}">
            <i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.members.permission_deny') }}</span>
        </label>
        <input class="btn-check" type="radio" name="{{ $name }}" id="{{ $toggleId }}_inherit" value="inherit"
            aria-describedby="{{ $toggleId }}_status" @checked($inherited)>
        <label class="btn btn-outline-secondary" for="{{ $toggleId }}_inherit" title="{{ $inheritLabel }}" data-permission-inherit-label>
            <i class="bi bi-dash-lg" aria-hidden="true"></i><span class="visually-hidden">{{ $inheritLabel }}</span>
        </label>
        <input class="btn-check" type="radio" name="{{ $name }}" id="{{ $toggleId }}_allow" value="{{ $allowValue }}"
            aria-describedby="{{ $toggleId }}_status" @checked($value === (string) $allowValue)>
        <label class="btn btn-outline-success" for="{{ $toggleId }}_allow" title="{{ __('admin.members.permission_allow') }}">
            <i class="bi bi-check-lg" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.members.permission_allow') }}</span>
        </label>
    </div>
</div>
@once
    @push('scripts')
        <script>
            (() => {
                const labels = {
                    inherit: {{ Js::from(__('admin.members.permission_inherit')) }},
                    allowed: {{ Js::from(__('admin.members.permission_allowed')) }},
                    denied: {{ Js::from(__('admin.members.permission_denied')) }},
                    defaultAllowed: {{ Js::from(__('admin.members.permission_status_default_allowed')) }},
                    defaultDenied: {{ Js::from(__('admin.members.permission_status_default_denied')) }},
                };
                const toggles = [...document.querySelectorAll('[data-permission-toggle]')];
                const profile = document.getElementById('permission_profile');
                const refresh = (toggle) => {
                    const selected = toggle.querySelector('input:checked');
                    if (!selected) {
                        return;
                    }
                    const inherited = selected.value === 'inherit';
                    const allowed = selected.value === toggle.dataset.permissionAllow ||
                        (inherited && toggle.dataset.permissionProvided === '1');
                    const status = toggle.querySelector('[data-permission-status]');
                    const icon = toggle.querySelector('[data-permission-status-icon]');
                    const text = inherited
                        ? (allowed ? labels.defaultAllowed : labels.defaultDenied)
                        : (allowed ? labels.allowed : labels.denied);
                    status.dataset.permissionState = allowed ? 'allowed' : 'denied';
                    status.classList.toggle('text-success', allowed);
                    status.classList.toggle('text-danger', !allowed);
                    icon.classList.toggle('bi-check-circle-fill', allowed);
                    icon.classList.toggle('bi-x-circle-fill', !allowed);
                    const statusText = toggle.querySelector('[data-permission-status-text]');
                    if (statusText.textContent !== text) {
                        statusText.textContent = text;
                    }
                };
                const updateProfile = () => {
                    toggles.forEach((toggle) => {
                        if (profile) {
                            const defaults = JSON.parse(toggle.dataset.profileDefaults);
                            const provided = defaults[profile.value] === true;
                            toggle.dataset.permissionProvided = provided ? '1' : '0';
                            const text = `${labels.inherit} — ${provided ? labels.allowed : labels.denied}`;
                            const inheritLabel = toggle.querySelector('[data-permission-inherit-label]');
                            inheritLabel.title = text;
                            inheritLabel.querySelector('.visually-hidden').textContent = text;
                        }
                        refresh(toggle);
                    });
                };
                toggles.forEach((toggle) => {
                    toggle.addEventListener('change', () => refresh(toggle));
                });
                profile?.addEventListener('change', updateProfile);
                window.addEventListener('pageshow', updateProfile);
                document.querySelectorAll('[data-permission-bulk]').forEach((button) => {
                    button.addEventListener('click', () => {
                        toggles.forEach((toggle) => {
                            if (toggle.dataset.permissionToggle === button.dataset.permissionTarget) {
                                toggle.querySelectorAll('input').forEach((input) => {
                                    input.checked = input.value === button.dataset.permissionBulk;
                                });
                                refresh(toggle);
                            }
                        });
                    });
                });
                updateProfile();
            })();
        </script>
    @endpush
@endonce
