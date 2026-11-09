@php
    $selectedScopes = old('_form') === $scopeForm ? old('scopes', $selectedScopes ?? []) : $selectedScopes ?? [];
    $selectedScopes = is_array($selectedScopes) ? $selectedScopes : [];
    $scopeDefinitions = $definitions;
    foreach ($selectedScopes as $selectedScope) {
        if (is_string($selectedScope) && !isset($scopeDefinitions[$selectedScope])) {
            $scopeDefinitions[$selectedScope] = null;
        }
    }
@endphp
@forelse ($scopeDefinitions as $scope => $definition)
    <div class="form-check mb-3">
        <input class="form-check-input" id="{{ $scopeId }}-{{ $loop->index }}" type="checkbox" name="scopes[]"
            value="{{ $scope }}" @checked(in_array($scope, $selectedScopes, true))>
        <label class="form-check-label"
            for="{{ $scopeId }}-{{ $loop->index }}">{{ $definition ? __($definition['label']) : __('service_accounts.unavailable_scope') }}
            <code>{{ $scope }}</code></label>
        <div class="form-text">
            {{ $definition ? __($definition['description']) : __('service_accounts.unavailable_help') }}</div>
    </div>
@empty
    <p class="text-body-secondary mb-0">{{ __('service_accounts.no_scopes') }}</p>
@endforelse
@error('scopes')
    <div class="invalid-feedback d-block">{{ $message }}</div>
@enderror
