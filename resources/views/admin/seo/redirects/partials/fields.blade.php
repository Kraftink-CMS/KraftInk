@php
    $redirectKey = $editingRedirect?->id ?? 'new';
@endphp
@php
    $redirectValue = fn($key, $fallback) => old('_modal') ===
    ($editingRedirect ? 'redirect-edit-' . $editingRedirect->id : 'redirect-new')
        ? old($key, $fallback)
        : $fallback;
@endphp
<div class="mb-3" data-redirect-source>
    <label class="form-label" for="source_{{ $redirectKey }}">{{ __('admin.redirects.source') }}</label>
    <input class="form-control" id="source_{{ $redirectKey }}" name="source"
        value="{{ $redirectValue('source', $editingRedirect?->source) }}" placeholder="https://example.com/old-page"
        required maxlength="4096" data-redirect-input>
    <div class="form-text">
        {{ __('admin.redirects.source_help') }}
    </div>
    <p class="small text-body-secondary mt-2 mb-0" data-redirect-preview hidden>{{ __('admin.redirects.source_preview') }} <code
            data-redirect-path></code></p>
</div>
<div class="mb-3">
    <label class="form-label" for="destination_{{ $redirectKey }}">{{ __('admin.redirects.destination') }}</label>
    <input class="form-control" id="destination_{{ $redirectKey }}" name="destination"
        value="{{ $redirectValue('destination', $editingRedirect?->destination) }}" required maxlength="1000">
    <div class="form-text">
        {{ __('admin.redirects.destination_help') }}
    </div>
</div>
<div class="mb-3">
    <label class="form-label" for="status_{{ $redirectKey }}">{{ __('admin.redirects.response_code') }}</label>
    <select class="form-select" id="status_{{ $redirectKey }}" name="status">
        @foreach ([301, 302] as $code)
            <option value="{{ $code }}" @selected((int) $redirectValue('status', $editingRedirect?->status ?? 301) === $code)>{{ __('admin.redirects.status_' . $code) }}</option>
        @endforeach
    </select>
</div>
<input type="hidden" name="enabled" value="0">
<div class="form-check form-switch">
    <input class="form-check-input" id="enabled_{{ $redirectKey }}" type="checkbox" name="enabled" value="1"
        @checked($redirectValue('enabled', $editingRedirect?->enabled ?? true))>
    <label class="form-check-label" for="enabled_{{ $redirectKey }}">{{ __('admin.redirects.enabled') }}</label>
</div>
